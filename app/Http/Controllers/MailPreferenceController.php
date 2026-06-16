<?php

namespace App\Http\Controllers;

use App\Models\MailCategory;
use App\Models\MailOptOut;
use App\Models\MailPushOptin;
use App\Services\MailOptOutService;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\Request;

/**
 * Zelfbeheer van meldingsvoorkeuren door de ingelogde gebruiker (Account → Meldingen).
 * Twee kanalen per categorie:
 *  - e-mail: opt-out via de e-mail-gekeyde mail_opt_outs (zelfde bron als de unsubscribe-link);
 *  - push:  opt-in via mail_push_optins (per account).
 * NB: route hangt onder /api/v1/* (user-token), NIET onder /admin.
 */
class MailPreferenceController extends Controller
{
    public function index(Request $request)
    {
        $user  = $request->user();
        $email = mb_strtolower(trim($user->email));

        $cats     = MailCategory::where('is_transactional', false)->orderBy('name')->get();
        $optOuts  = MailOptOut::where('email', $email)->get();
        $global   = $optOuts->contains(fn ($o) => is_null($o->category_id));
        $pushCats = MailPushOptin::where('user_id', $user->id)->pluck('category_id')->flip();

        $data = $cats->map(fn ($c) => [
            'id'               => $c->id,
            'key'              => $c->key,
            'name'             => $c->name,
            'description'      => $c->description,
            // e-mail: afgemeld als er een globale opt-out is, of een opt-out voor deze categorie
            'email_subscribed' => ! $global && ! $optOuts->contains(fn ($o) => (int) $o->category_id === $c->id),
            // push: aan als er een opt-in-rij is
            'push_enabled'     => isset($pushCats[$c->id]),
        ]);

        return response()->json(['data' => $data, 'global_opt_out' => $global]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'channel'     => ['nullable', 'in:email,push'],
            'category_id' => ['nullable', 'integer', 'exists:mail_categories,id'],
            'subscribed'  => ['required', 'boolean'],
        ]);

        $user    = $request->user();
        $email   = mb_strtolower(trim($user->email));
        $catId   = $data['category_id'] ?? null;
        $channel = $data['channel'] ?? 'email';

        // Transactionele categorieën zijn niet uit te zetten (login/reset etc.) — ze
        // verschijnen ook niet in index(), dus dit blokkeert alleen rechtstreekse API-calls.
        if ($catId !== null && MailCategory::where('id', $catId)->where('is_transactional', true)->exists()) {
            return response()->json(['message' => 'Deze categorie kan niet worden gewijzigd.'], 422);
        }

        // ── Push-kanaal (opt-in, alleen per categorie) ──
        if ($channel === 'push') {
            if ($catId === null) {
                return response()->json(['message' => 'Categorie vereist voor push.'], 422);
            }
            NotificationPreferenceService::setPush($user->id, $catId, (bool) $data['subscribed']);

            return response()->json(['ok' => true]);
        }

        // ── E-mailkanaal (opt-out) ──
        if ($data['subscribed']) {
            // Eén categorie weer aanzetten terwijl er een GLOBALE afmelding staat zou
            // gemaskeerd worden door die globale rij. Zet 'm dan om: globaal eraf, en
            // de overige (niet-transactionele) categorieën expliciet op afgemeld — netto
            // blijft alleen deze categorie aan en werkt de schakelaar zoals verwacht.
            if ($catId !== null && MailOptOut::where('email', $email)->whereNull('category_id')->exists()) {
                $others = MailCategory::where('is_transactional', false)->where('id', '!=', $catId)->pluck('id');
                foreach ($others as $oid) {
                    MailOptOutService::optOut($email, (int) $oid, 'account');
                }
                MailOptOutService::optIn($email, null); // globale afmelding opheffen
            }
            MailOptOutService::optIn($email, $catId);
        } else {
            MailOptOutService::optOut($email, $catId, 'account');
        }

        return response()->json(['ok' => true]);
    }
}
