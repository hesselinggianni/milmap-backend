<?php

namespace App\Strategy;

use App\Contracts\StrategyInterface;
use App\Indicators\AverageTrueRange;
use App\Indicators\Ema;
use App\Indicators\Rsi;
use App\Models\Position;
use App\Services\AnthropicClient;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;

/**
 * The Claude "skill": Claude analyses recent candles plus computed indicators
 * and returns a structured trade signal.
 *
 * Safety rails baked in here (the RiskManager still runs afterwards):
 *  - fail-safe to HOLD on any API failure or unconfigured key;
 *  - a hard per-day call cap so the bot can never run up a large bill;
 *  - the model can only ever *propose* — it cannot bypass risk limits.
 */
final class LlmClaudeStrategy implements StrategyInterface
{
    public function __construct(private readonly AnthropicClient $client) {}

    public function key(): string
    {
        return 'llm_claude';
    }

    public function decide(array $candles, ?Position $current, array $params): Signal
    {
        if (! $this->client->isConfigured()) {
            return Signal::hold('LLM key not configured — failing safe to hold');
        }

        $maxCallsPerDay = (int) ($params['max_calls_per_day'] ?? config('bot.llm.max_calls_per_day', 24));
        $sizeCap = (float) ($params['max_position_size_pct'] ?? 0.20);

        $closes = array_map(fn ($c) => $c->close, $candles);
        if (count($closes) < 30) {
            return Signal::hold('insufficient candle history for LLM');
        }

        // Hard daily call cap — fail safe to hold once exhausted.
        $cacheKey = 'bot:llm:calls:'.now()->format('Y-m-d');
        $used = (int) Cache::get($cacheKey, 0);
        if ($used >= $maxCallsPerDay) {
            return Signal::hold('daily LLM call cap reached');
        }

        $indicators = [
            'ema_fast' => (string) Ema::last($closes, 12),
            'ema_slow' => (string) Ema::last($closes, 26),
            'rsi_14' => (string) Rsi::calc($closes, 14),
            'atr_14' => (string) AverageTrueRange::calc($candles, 14),
            'last_close' => (string) $closes[count($closes) - 1],
        ];

        $position = $current && BigDecimal::of((string) $current->quantity)->isGreaterThan(BigDecimal::zero())
            ? ['quantity' => (string) $current->quantity, 'avg_entry_price' => (string) $current->avg_entry_price]
            : null;

        $decision = $this->client->decide(
            $this->systemPrompt($sizeCap),
            $this->userPrompt($indicators, $position, array_slice($closes, -30))
        );

        Cache::put($cacheKey, $used + 1, now()->endOfDay());

        if ($decision === null) {
            return Signal::hold('LLM returned no parseable decision — failing safe to hold', ['indicators' => $indicators]);
        }

        $meta = ['indicators' => $indicators, 'llm' => $decision];
        $confidence = max(0.0, min(1.0, $decision['confidence']));

        return match ($decision['side']) {
            Signal::BUY => Signal::buy(min($decision['size_pct'], $sizeCap), 'LLM: '.$decision['reason'], $confidence, $meta),
            Signal::SELL => Signal::sell('LLM: '.$decision['reason'], $confidence, $meta),
            default => Signal::hold('LLM: '.$decision['reason'], $meta),
        };
    }

    private function systemPrompt(float $sizeCap): string
    {
        return <<<TXT
        You are a disciplined, risk-averse crypto trading assistant operating in a
        PAPER-TRADING simulation. You are given recent price candles and computed
        technical indicators for a single market. Decide whether to buy, sell, or hold.

        Rules:
        - Default to "hold" when the signal is unclear. Capital preservation matters more than activity.
        - "size_pct" is the fraction of equity to allocate on a buy; never exceed {$sizeCap}.
        - Base your reasoning only on the data provided. Do not invent news or prices.
        - Automated crypto trading usually loses money after fees; be conservative.
        Return your decision via the submit_trade_decision tool.
        TXT;
    }

    private function userPrompt(array $indicators, ?array $position, array $recentCloses): string
    {
        $ind = json_encode($indicators, JSON_PRETTY_PRINT);
        $pos = $position ? json_encode($position, JSON_PRETTY_PRINT) : 'none (flat)';
        $closes = implode(', ', array_map(fn ($c) => (string) $c, $recentCloses));

        return <<<TXT
        Market snapshot (Bitvavo, 1h candles).

        Computed indicators:
        {$ind}

        Current open position:
        {$pos}

        Last 30 closing prices (oldest -> newest):
        {$closes}

        Decide: buy, sell, or hold.
        TXT;
    }
}
