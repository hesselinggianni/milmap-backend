<?php

namespace App\Exchange;

use App\Contracts\ExchangeInterface;
use App\Exchange\Dto\Candle;
use App\Exchange\Dto\OrderRequest;
use App\Exchange\Dto\OrderResult;
use App\Exchange\Dto\Ticker;
use Brick\Math\BigDecimal;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Bitvavo REST client.
 *
 * Public market-data methods (candles, ticker) require no authentication and
 * are the only methods used in phase 1. The private methods are fully written,
 * including HMAC-SHA256 request signing, but they refuse to run until the
 * real-trading gate is open AND API credentials are present — see phase 3.
 */
final class BitvavoClient implements ExchangeInterface
{
    private const BASE_URL = 'https://api.bitvavo.com/v2';

    public function __construct(
        private readonly bool $realEnabled = false,
        private readonly ?string $apiKey = null,
        private readonly ?string $apiSecret = null,
    ) {}

    public function name(): string
    {
        return 'bitvavo';
    }

    public function isLive(): bool
    {
        return true;
    }

    /** @return Candle[] ascending by time */
    public function candles(string $market, string $interval, int $limit = 200): array
    {
        $response = $this->http()->get(self::BASE_URL."/{$market}/candles", [
            'interval' => $interval,
            'limit' => $limit,
        ])->throw();

        // Bitvavo returns newest-first; reverse to ascending (oldest-first).
        $rows = array_reverse($response->json());

        return array_map(fn ($row) => Candle::fromBitvavo($row), $rows);
    }

    public function ticker(string $market): Ticker
    {
        $response = $this->http()->get(self::BASE_URL.'/ticker/price', [
            'market' => $market,
        ])->throw();

        return new Ticker($market, BigDecimal::of((string) $response->json('price')));
    }

    // --- Private (money-moving) methods — gated. ---

    public function balances(): array
    {
        $this->assertRealTradingAllowed();

        $response = $this->signedRequest('GET', '/balance')->throw();

        return array_map(fn ($row) => new Dto\Balance(
            asset: $row['symbol'],
            available: BigDecimal::of((string) $row['available']),
            reserved: BigDecimal::of((string) $row['inOrder']),
        ), $response->json());
    }

    public function placeOrder(OrderRequest $request): OrderResult
    {
        $this->assertRealTradingAllowed();

        // Phase 3: submit a real market order and map the fill back to OrderResult.
        // Intentionally not implemented in the paper-trading MVP.
        throw new \LogicException('Live order placement is not implemented in the paper-trading MVP.');
    }

    public function cancelOrder(string $market, string $orderId): void
    {
        $this->assertRealTradingAllowed();

        throw new \LogicException('Live order cancellation is not implemented in the paper-trading MVP.');
    }

    private function http(): PendingRequest
    {
        // Respect Bitvavo rate limits by retrying with backoff on 429/5xx.
        return Http::timeout(15)->retry(3, 500, throw: false);
    }

    private function assertRealTradingAllowed(): void
    {
        if (! $this->realEnabled || empty($this->apiKey) || empty($this->apiSecret)) {
            throw RealTradingDisabledException::make();
        }
    }

    /**
     * Signed private request. HMAC-SHA256 over
     * timestamp + method + '/v2' + path + body, per the Bitvavo API spec.
     * Written for phase 3; not exercised while real trading is disabled.
     */
    private function signedRequest(string $method, string $path, array $body = []): \Illuminate\Http\Client\Response
    {
        $timestamp = (int) (microtime(true) * 1000);
        $bodyJson = $body === [] ? '' : json_encode($body);
        $payload = $timestamp.$method.'/v2'.$path.$bodyJson;
        $signature = hash_hmac('sha256', $payload, (string) $this->apiSecret);

        $request = $this->http()->withHeaders([
            'Bitvavo-Access-Key' => $this->apiKey,
            'Bitvavo-Access-Signature' => $signature,
            'Bitvavo-Access-Timestamp' => $timestamp,
            'Bitvavo-Access-Window' => 10000,
        ]);

        return $method === 'GET'
            ? $request->get(self::BASE_URL.$path)
            : $request->send($method, self::BASE_URL.$path, ['json' => $body]);
    }
}
