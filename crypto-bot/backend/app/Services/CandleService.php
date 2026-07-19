<?php

namespace App\Services;

use App\Contracts\ExchangeInterface;
use App\Exchange\Dto\Candle as CandleDto;
use App\Models\Candle;
use Brick\Math\BigDecimal;

/**
 * Fetches candles from the exchange and upserts them into the local cache,
 * which doubles as the historical series for back-testing. Also exposes the
 * candle window the engine feeds to strategies.
 */
final class CandleService
{
    public function __construct(private readonly ExchangeInterface $exchange) {}

    /** Fetch the latest candles and upsert them (dedupe on market+interval+open_time). */
    public function sync(string $market, string $interval, int $limit = 200): int
    {
        $candles = $this->exchange->candles($market, $interval, $limit);

        $rows = array_map(fn (CandleDto $c) => [
            'market' => $market,
            'interval' => $interval,
            'open_time' => $c->openTime,
            'open' => (string) $c->open,
            'high' => (string) $c->high,
            'low' => (string) $c->low,
            'close' => (string) $c->close,
            'volume' => (string) $c->volume,
            'updated_at' => now(),
            'created_at' => now(),
        ], $candles);

        if ($rows !== []) {
            Candle::upsert($rows, ['market', 'interval', 'open_time'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
        }

        return count($rows);
    }

    /**
     * The candle window for a strategy, oldest -> newest, from the local cache.
     *
     * @return CandleDto[]
     */
    public function window(string $market, string $interval, int $limit = 200): array
    {
        return Candle::query()
            ->where('market', $market)
            ->where('interval', $interval)
            ->orderByDesc('open_time')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn (Candle $c) => new CandleDto(
                openTime: (int) $c->open_time,
                open: BigDecimal::of((string) $c->open),
                high: BigDecimal::of((string) $c->high),
                low: BigDecimal::of((string) $c->low),
                close: BigDecimal::of((string) $c->close),
                volume: BigDecimal::of((string) $c->volume),
            ))
            ->values()
            ->all();
    }
}
