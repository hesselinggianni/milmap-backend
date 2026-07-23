<?php

/**
 * The single control surface for the bot. Every safety knob lives here and is
 * driven by environment variables. Defaults are deliberately conservative and
 * keep the bot in paper mode with real trading firmly off.
 */
return [
    // Master enable — nothing trades until this is true. Default OFF.
    'enabled' => env('BOT_ENABLED', false),

    // Runtime kill-switch (also settable at runtime via `bot:halt`).
    'kill_switch' => env('BOT_KILL_SWITCH', false),

    // Static bearer token for the dashboard API (empty = open, local dev only).
    'api_token' => env('BOT_API_TOKEN', ''),

    // Markets to trade. Start with ONE.
    'pairs' => array_filter(array_map('trim', explode(',', env('BOT_PAIRS', 'BTC-EUR')))),

    'candle' => [
        'interval' => env('BOT_CANDLE_INTERVAL', '1h'),
    ],

    'paper' => [
        'starting_balance_eur' => env('BOT_PAPER_STARTING_EUR', 1000),
        'fee_bps' => env('BOT_PAPER_FEE_BPS', 25),        // Bitvavo taker ~0.25%
        'slippage_bps' => env('BOT_PAPER_SLIPPAGE_BPS', 5),
    ],

    // Real trading gate — one of the three locks. Never flip without signed keys.
    'real' => [
        'enabled' => env('BOT_REAL_ENABLED', false),
    ],

    // Conservative risk limits.
    'risk' => [
        'max_position_pct' => env('BOT_MAX_POSITION_PCT', 0.20),   // 20% of equity per position
        'max_position_eur' => env('BOT_MAX_POSITION_EUR', 250),    // absolute cap
        'max_total_exposure_pct' => env('BOT_MAX_TOTAL_EXPOSURE_PCT', 0.60), // across all coins combined
        'max_daily_loss_pct' => env('BOT_MAX_DAILY_LOSS_PCT', 0.05),
        'max_drawdown_pct' => env('BOT_MAX_DRAWDOWN_PCT', 0.20),
        'min_order_eur' => env('BOT_MIN_ORDER_EUR', 10),
    ],

    // Auto-deposit ("leg automatisch in en stop"). Paper mode only.
    'deposit' => [
        'enabled' => env('BOT_DEPOSIT_ENABLED', false),
        'per_topup_eur' => env('BOT_DEPOSIT_PER_TOPUP_EUR', 100),
        'max_lifetime_eur' => env('BOT_DEPOSIT_MAX_LIFETIME_EUR', 1000),
        'stop_on_cap' => env('BOT_DEPOSIT_STOP_ON_CAP', true),
    ],

    // LLM strategy (Claude).
    'llm' => [
        'model' => env('BOT_LLM_MODEL', 'claude-opus-4-8'),
        'max_calls_per_day' => env('BOT_LLM_MAX_CALLS_PER_DAY', 24),
    ],

    // Bitvavo credentials — only used in phase 3 (real trading).
    'bitvavo' => [
        'api_key' => env('BITVAVO_API_KEY'),
        'api_secret' => env('BITVAVO_API_SECRET'),
    ],
];
