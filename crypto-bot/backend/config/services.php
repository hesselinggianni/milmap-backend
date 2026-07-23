<?php

return [
    // Anthropic API key for the Claude-driven strategy.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('BOT_LLM_MODEL', 'claude-opus-4-8'),
    ],
];
