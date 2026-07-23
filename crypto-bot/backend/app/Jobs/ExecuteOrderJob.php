<?php

namespace App\Jobs;

use App\Contracts\ExchangeInterface;
use App\Exchange\Dto\OrderRequest;
use App\Exchange\Dto\OrderResult;
use App\Models\EventLog;
use App\Models\Order;
use App\Models\Position;
use App\Models\Trade;
use App\Models\WalletBalance;
use App\Support\Quantity;
use Brick\Math\BigDecimal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Executes one order through the (paper) exchange and applies the fill to the
 * virtual wallet and position atomically. Idempotent: an order that is not
 * still `pending` is skipped. Because it goes through ExchangeInterface,
 * switching to a live exchange in phase 3 changes nothing here.
 */
class ExecuteOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $orderId) {}

    public function handle(ExchangeInterface $exchange): void
    {
        $order = Order::find($this->orderId);
        if (! $order || $order->status !== 'pending') {
            return; // idempotent: already handled or gone.
        }

        [$base, $quote] = $this->splitMarket($order->market);

        $result = $exchange->placeOrder(new OrderRequest(
            market: $order->market,
            side: $order->side,
            quantity: new Quantity((string) $order->requested_qty, $base),
            clientOrderId: $order->client_order_id,
        ));

        DB::transaction(function () use ($order, $result, $base, $quote) {
            $this->applyFill($order, $result, $base, $quote);
        });
    }

    private function applyFill(Order $order, OrderResult $result, string $base, string $quote): void
    {
        $qty = $result->filledQuantity->amount();
        $price = $result->filledPrice;
        $fee = $result->fee->amount();
        $notional = $qty->multipliedBy($price);

        Trade::create([
            'order_id' => $order->id,
            'account_id' => $order->account_id,
            'market' => $order->market,
            'side' => $order->side,
            'price' => (string) $price,
            'quantity' => (string) $qty,
            'fee' => (string) $fee,
            'fee_asset' => $quote,
            'executed_at' => now(),
        ]);

        $quoteWallet = $this->wallet($order->account_id, $quote);
        $baseWallet = $this->wallet($order->account_id, $base);

        if ($order->side === 'buy') {
            // Pay notional + fee in quote; receive base.
            $quoteWallet->available = (string) BigDecimal::of((string) $quoteWallet->available)->minus($notional)->minus($fee);
            $baseWallet->available = (string) BigDecimal::of((string) $baseWallet->available)->plus($qty);
            $this->openOrIncreasePosition($order, $qty, $price);
        } else {
            // Receive proceeds - fee in quote; deliver base.
            $quoteWallet->available = (string) BigDecimal::of((string) $quoteWallet->available)->plus($notional)->minus($fee);
            $baseWallet->available = (string) BigDecimal::of((string) $baseWallet->available)->minus($qty);
            $this->reducePosition($order, $qty, $price, $fee);
        }

        $quoteWallet->save();
        $baseWallet->save();

        $order->update([
            'status' => 'filled',
            'exchange_order_id' => $result->exchangeOrderId,
        ]);

        EventLog::record('order_filled', "filled {$order->side} {$qty} {$order->market} @ {$price}", [
            'order_id' => $order->id,
            'notional' => (string) $notional,
            'fee' => (string) $fee,
        ], 'info', $order->account_id);
    }

    private function openOrIncreasePosition(Order $order, BigDecimal $qty, BigDecimal $price): void
    {
        $position = Position::where('account_id', $order->account_id)
            ->where('market', $order->market)
            ->where('status', 'open')
            ->first();

        if ($position === null) {
            Position::create([
                'account_id' => $order->account_id,
                'market' => $order->market,
                'quantity' => (string) $qty,
                'avg_entry_price' => (string) $price,
                'opened_at' => now(),
                'status' => 'open',
                'realized_pnl' => '0',
            ]);

            return;
        }

        $oldQty = BigDecimal::of((string) $position->quantity);
        $oldAvg = BigDecimal::of((string) $position->avg_entry_price);
        $newQty = $oldQty->plus($qty);
        // Weighted average entry price.
        $newAvg = $oldQty->multipliedBy($oldAvg)->plus($qty->multipliedBy($price))
            ->dividedBy($newQty, 10, \Brick\Math\RoundingMode::HALF_UP);

        $position->update(['quantity' => (string) $newQty, 'avg_entry_price' => (string) $newAvg]);
    }

    private function reducePosition(Order $order, BigDecimal $qty, BigDecimal $price, BigDecimal $fee): void
    {
        $position = Position::where('account_id', $order->account_id)
            ->where('market', $order->market)
            ->where('status', 'open')
            ->first();

        if ($position === null) {
            return;
        }

        $avg = BigDecimal::of((string) $position->avg_entry_price);
        $realized = $price->minus($avg)->multipliedBy($qty)->minus($fee);
        $remaining = BigDecimal::of((string) $position->quantity)->minus($qty);
        $newRealized = BigDecimal::of((string) $position->realized_pnl)->plus($realized);

        $position->update([
            'quantity' => (string) $remaining,
            'realized_pnl' => (string) $newRealized,
            'status' => $remaining->isGreaterThan(BigDecimal::of('0.0000000001')) ? 'open' : 'closed',
        ]);
    }

    private function wallet(int $accountId, string $asset): WalletBalance
    {
        return WalletBalance::firstOrCreate(
            ['account_id' => $accountId, 'asset' => $asset],
            ['available' => '0', 'reserved' => '0'],
        );
    }

    /** @return array{0:string,1:string} [baseAsset, quoteAsset] for "BTC-EUR" => ["BTC","EUR"] */
    private function splitMarket(string $market): array
    {
        [$base, $quote] = explode('-', $market);

        return [$base, $quote];
    }

    public function failed(\Throwable $e): void
    {
        $order = Order::find($this->orderId);
        if ($order && $order->status === 'pending') {
            $order->update(['status' => 'rejected', 'reject_reason' => $e->getMessage()]);
        }
        EventLog::record('order_placed', 'order execution failed', [
            'order_id' => $this->orderId,
            'error' => $e->getMessage(),
        ], 'critical', $order?->account_id);
    }
}
