<?php

namespace App\Http\Controllers;

use App\Mail\CampaignMail;
use App\Models\MailSend;
use App\Services\MailTemplateRegistry;
use Illuminate\Http\Request;

/**
 * In-app mail-viewer: rendert de HTML van een campagne-mail voor de ingelogde gebruiker,
 * zodat een push-melding (App\Models\AppNotification met action=campaign_mail) de mail
 * in de app onder Meldingen kan tonen. De token moet bij de ingelogde gebruiker horen.
 */
class MeCampaignMailController extends Controller
{
    public function show(Request $request, string $token)
    {
        $send = MailSend::where('token', $token)->first();
        if (! $send) {
            return response()->json(['message' => 'Niet gevonden.'], 404);
        }

        $user = $request->user();
        $owns = ($send->user_id && (int) $send->user_id === (int) $user->id)
            || mb_strtolower(trim((string) $send->email)) === mb_strtolower(trim((string) $user->email));
        if (! $owns) {
            return response()->json(['message' => 'Geen toegang tot dit bericht.'], 403);
        }

        $resolved = MailTemplateRegistry::resolve($send->template_key, $send->language);
        if (! $resolved) {
            return response()->json(['message' => 'Sjabloon niet gevonden.'], 404);
        }

        $appUrl = config('app.app_url', 'https://app.milmap.nl');
        $mail = new CampaignMail(
            $resolved['view'],
            $send->subject ?: $resolved['subject'],
            ['name' => $send->name, 'appUrl' => $appUrl, 'siteUrl' => 'https://milmap.nl'],
            null, // geen afmeldlink/pixel in de in-app weergave
            null,
        );

        return response()->json([
            'subject' => $send->subject ?: $resolved['subject'],
            'html'    => $mail->render(),
        ]);
    }
}
