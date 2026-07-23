<?php

namespace App\Providers;

use App\Contracts\ExchangeInterface;
use App\Exchange\BitvavoClient;
use App\Exchange\PaperExchange;
use App\Services\AnthropicClient;
use Illuminate\Support\ServiceProvider;

class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The real Bitvavo client (public data always; private methods gated).
        $this->app->singleton(BitvavoClient::class, function () {
            return new BitvavoClient(
                realEnabled: (bool) config('bot.real.enabled', false),
                apiKey: config('bot.bitvavo.api_key'),
                apiSecret: config('bot.bitvavo.api_secret'),
            );
        });

        // The simulated exchange wraps the real client for live prices.
        $this->app->singleton(PaperExchange::class, function ($app) {
            return new PaperExchange(
                marketData: $app->make(BitvavoClient::class),
                feeBps: (int) config('bot.paper.fee_bps', 25),
                slippageBps: (int) config('bot.paper.slippage_bps', 5),
            );
        });

        // Lock #1: bind the interface to PaperExchange unless real trading is on.
        $this->app->bind(ExchangeInterface::class, function ($app) {
            return config('bot.real.enabled', false)
                ? $app->make(BitvavoClient::class)
                : $app->make(PaperExchange::class);
        });

        $this->app->singleton(AnthropicClient::class, function () {
            return new AnthropicClient(
                apiKey: config('services.anthropic.key'),
                model: (string) config('services.anthropic.model', 'claude-opus-4-8'),
            );
        });
    }

    public function boot(): void
    {
        // Lock #2 (boot assertion): refuse to run with real trading enabled but no keys.
        if (config('bot.real.enabled', false)
            && (empty(config('bot.bitvavo.api_key')) || empty(config('bot.bitvavo.api_secret')))) {
            throw new \RuntimeException(
                'BOT_REAL_ENABLED is true but Bitvavo API credentials are missing. '.
                'Refusing to boot to avoid an unsafe live configuration.'
            );
        }
    }
}
