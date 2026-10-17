<?php

namespace App\Http\Controllers;

use App\Services\AccountReset;
use App\Services\ConnectManagement;
use Illuminate\Http\Request;

/**
 * POST /api/v1/user/account/reset — gebruiker wist zelf al zijn data; het
 * account blijft (zie AccountReset). Bij Connect beslist het organisatiebeleid
 * (recht reset_account, standaard aan) via EnforceConnectManagement.
 */
class AccountResetController extends Controller
{
    public function reset(Request $request, AccountReset $reset, ConnectManagement $management)
    {
        $request->validate(['confirm' => 'required|in:RESET']);
        $user = $request->user();

        $counts = $reset->reset($user);

        $context = $management->context($user);
        if ($context['managed'] && !empty($context['organization']['id'])) {
            $management->audit($context['organization']['id'], 'account.reset', 'User', (string) $user->id, ['counts' => $counts, 'by' => 'self']);
        }

        return response()->json(['message' => 'Account gereset.', 'deleted' => $counts, 'data_reset_at' => $user->fresh()->data_reset_at]);
    }
}
