<?php

namespace App\Http\Controllers;

use App\Models\MailSend;
use App\Services\MailOptOutService;
use Illuminate\Http\Request;

/**
 * Publieke afmeldpagina via de token uit een verzonden mail (mail_sends.token). De token
 * draagt e-mail + categorie, dus afmelden kan zonder inloggen. Ondersteunt zowel de
 * bevestigingspagina (browser) als one-click afmelden (RFC 8058 List-Unsubscribe-Post).
 *
 * Routes hangen onder /api/v1/m/* in routes/api.php — bewust NIET in web.php, want daar
 * vangt de SPA-catch-all (/{any}) alles op.
 */
class MailUnsubscribeController extends Controller
{
    public function show(string $token)
    {
        $send = MailSend::with('category')->where('token', $token)->first();
        if (! $send) {
            return response()->view('mail.unsubscribe', ['invalid' => true], 200);
        }

        return response()->view('mail.unsubscribe', [
            'invalid'      => false,
            'token'        => $token,
            'email'        => $send->email,
            'categoryName' => $send->category?->name ?? 'marketing-e-mails',
            'optedOut'     => MailOptOutService::isOptedOut($send->email, $send->category_id),
            'done'         => false,
        ]);
    }

    public function update(Request $request, string $token)
    {
        $send = MailSend::with('category')->where('token', $token)->first();
        if (! $send) {
            return $request->expectsJson()
                ? response()->json(['ok' => false], 404)
                : response()->view('mail.unsubscribe', ['invalid' => true], 200);
        }

        // scope: category (standaard) | all (alle marketing) | resubscribe
        $scope = $request->input('scope', 'category');
        // One-click vanuit de mailclient stuurt 'List-Unsubscribe=One-Click' → categorie.
        $oneClick = $request->input('List-Unsubscribe') === 'One-Click';

        if ($scope === 'all') {
            MailOptOutService::optOut($send->email, null, 'unsubscribe_link');
        } elseif ($scope === 'resubscribe') {
            MailOptOutService::optIn($send->email, $send->category_id);
        } else {
            MailOptOutService::optOut($send->email, $send->category_id, 'unsubscribe_link');
        }

        if ($oneClick || $request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return response()->view('mail.unsubscribe', [
            'invalid'      => false,
            'token'        => $token,
            'email'        => $send->email,
            'categoryName' => $send->category?->name ?? 'marketing-e-mails',
            'optedOut'     => $scope !== 'resubscribe',
            'done'         => true,
            'resubscribed' => $scope === 'resubscribe',
        ]);
    }
}
