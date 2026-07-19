<?php

namespace App\Services;

use App\Models\Account;
use App\Models\EventLog;
use App\Models\WalletBalance;
use Brick\Math\BigDecimal;

/**
 * Automatic (virtual) top-ups — "leg automatisch geld in en stop".
 *
 * In paper mode this credits virtual EUR to the wallet on a schedule, with
 * hard caps: a per-top-up amount and a lifetime cap. Once cumulative deposits
 * reach the lifetime cap, top-ups stop automatically. The lifetime cap is the
 * ceiling on how much could ever be lost.
 *
 * Real funding is NEVER automated — that stays manual, by design.
 */
final class DepositService
{
    public function cumulativeDeposited(Account $account): BigDecimal
    {
        $sum = BigDecimal::zero();
        $events = EventLog::where('account_id', $account->id)->where('type', 'deposit')->get();
        foreach ($events as $event) {
            $sum = $sum->plus(BigDecimal::of((string) ($event->context['amount'] ?? '0')));
        }

        return $sum;
    }

    /** Perform one scheduled top-up. Returns the amount credited (may be zero). */
    public function topUp(Account $account): BigDecimal
    {
        if (! config('bot.deposit.enabled', false)) {
            EventLog::record('deposit', 'top-up skipped (auto-deposit disabled)', [], 'info', $account->id);

            return BigDecimal::zero();
        }

        $perTopUp = BigDecimal::of((string) config('bot.deposit.per_topup_eur', 100));
        $lifetimeCap = BigDecimal::of((string) config('bot.deposit.max_lifetime_eur', 1000));
        $stopOnCap = (bool) config('bot.deposit.stop_on_cap', true);

        $cumulative = $this->cumulativeDeposited($account);

        if ($stopOnCap && $cumulative->isGreaterThanOrEqualTo($lifetimeCap)) {
            EventLog::record('deposit', 'lifetime deposit cap reached — top-ups stopped', [
                'cumulative' => (string) $cumulative,
                'cap' => (string) $lifetimeCap,
            ], 'warning', $account->id);

            return BigDecimal::zero();
        }

        // Clamp so we never exceed the lifetime cap.
        $remaining = $lifetimeCap->minus($cumulative);
        $amount = $perTopUp->isGreaterThan($remaining) ? $remaining : $perTopUp;

        if (! $amount->isGreaterThan(BigDecimal::zero())) {
            return BigDecimal::zero();
        }

        $wallet = WalletBalance::firstOrCreate(
            ['account_id' => $account->id, 'asset' => $account->base_currency],
            ['available' => '0', 'reserved' => '0'],
        );
        $wallet->available = (string) BigDecimal::of((string) $wallet->available)->plus($amount);
        $wallet->save();

        EventLog::record('deposit', 'virtual top-up credited', [
            'amount' => (string) $amount,
            'cumulative' => (string) $cumulative->plus($amount),
            'cap' => (string) $lifetimeCap,
        ], 'info', $account->id);

        return $amount;
    }
}
