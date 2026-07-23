<?php

namespace App\Services;

use App\Contracts\StrategyInterface;
use App\Strategy\EmaCrossRsiStrategy;
use App\Strategy\LlmClaudeStrategy;
use Illuminate\Contracts\Container\Container;

/** Maps a strategy_key to its concrete StrategyInterface implementation. */
final class StrategyResolver
{
    /** @var array<string,class-string<StrategyInterface>> */
    private array $map = [
        'ema_cross_rsi' => EmaCrossRsiStrategy::class,
        'llm_claude' => LlmClaudeStrategy::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function resolve(string $key): StrategyInterface
    {
        if (! isset($this->map[$key])) {
            throw new \InvalidArgumentException("Unknown strategy: {$key}");
        }

        return $this->container->make($this->map[$key]);
    }

    /** @return string[] */
    public function available(): array
    {
        return array_keys($this->map);
    }
}
