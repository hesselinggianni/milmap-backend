<?php

namespace App\Http\Controllers\Api;

use App\Models\EventLog;
use App\Models\StrategyConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** Limited write endpoints: kill-switch and strategy activation. */
class ControlController
{
    public function halt(): JsonResponse
    {
        Cache::forever('bot:halt', true);
        EventLog::record('kill_switch', 'runtime halt engaged via API', [], 'critical');

        return response()->json(['kill_switch' => true]);
    }

    public function resume(): JsonResponse
    {
        Cache::forget('bot:halt');
        EventLog::record('kill_switch', 'runtime halt cleared via API', [], 'warning');

        return response()->json(['kill_switch' => false]);
    }

    public function activateStrategy(int $id): JsonResponse
    {
        $config = StrategyConfig::findOrFail($id);

        // Only one active config per market/account at a time.
        StrategyConfig::where('account_id', $config->account_id)
            ->where('market', $config->market)
            ->update(['is_active' => false]);
        $config->update(['is_active' => true]);

        EventLog::record('signal', "strategy '{$config->strategy_key}' activated for {$config->market}", [], 'info', $config->account_id);

        return response()->json($config->fresh());
    }
}
