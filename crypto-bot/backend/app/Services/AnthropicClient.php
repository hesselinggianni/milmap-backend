<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Anthropic Messages API. Uses strict tool-use so the
 * model is forced to return a structured trade decision rather than free text
 * we would have to guess-parse. Returns null on any failure so callers can fail
 * safe to "hold".
 */
final class AnthropicClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $model = 'claude-opus-4-8',
    ) {}

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Ask the model for a trade decision.
     *
     * @return array{side:string,size_pct:float,confidence:float,reason:string}|null
     */
    public function decide(string $system, string $userPrompt): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $tool = [
            'name' => 'submit_trade_decision',
            'description' => 'Submit the trading decision for the current market snapshot.',
            'strict' => true,
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'side' => ['type' => 'string', 'enum' => ['buy', 'sell', 'hold']],
                    'size_pct' => ['type' => 'number', 'description' => 'Fraction of equity to allocate on a buy, 0..1.'],
                    'confidence' => ['type' => 'number', 'description' => 'Confidence 0..1.'],
                    'reason' => ['type' => 'string', 'description' => 'One-sentence rationale.'],
                ],
                'required' => ['side', 'size_pct', 'confidence', 'reason'],
            ],
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => $system,
                'tools' => [$tool],
                'tool_choice' => ['type' => 'tool', 'name' => 'submit_trade_decision'],
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

            if (! $response->successful()) {
                Log::channel('bot')->warning('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            foreach ($response->json('content', []) as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'submit_trade_decision') {
                    $input = $block['input'] ?? [];

                    return [
                        'side' => (string) ($input['side'] ?? 'hold'),
                        'size_pct' => (float) ($input['size_pct'] ?? 0.0),
                        'confidence' => (float) ($input['confidence'] ?? 0.0),
                        'reason' => (string) ($input['reason'] ?? ''),
                    ];
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::channel('bot')->warning('Anthropic call failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
