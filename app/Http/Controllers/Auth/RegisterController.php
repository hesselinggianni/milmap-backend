<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewUserRegistered;
use App\Models\ChatInvite;
use App\Models\Conversation;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    public function store(Request $request)
    {

           // Check if the user already exists
           $existingUser = User::where('email', $request->email)->first();

           if ($existingUser) {
               if ($existingUser->isArchived()) {
                   // Gearchiveerd account (>90 dagen ongeverifieerd): geef het
                   // e-mailslot vrij zodat ditzelfde adres opnieuw kan registreren.
                   // Het dode account behoudt z'n data maar krijgt een vrijgemaakt
                   // e-mailadres en kan niet meer inloggen.
                   $existingUser->forceFill([
                       'email' => $existingUser->email . '.archived.' . $existingUser->id,
                   ])->save();
                   $existingUser->tokens()->delete();
               } else {
                   throw ValidationException::withMessages([
                       'email' => ['Deze gebruiker is al geregistreerd.'],
                   ]);
               }
           }

           
        // Validate input data
        $validator = Validator::make($request->all(), [       
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        // Share-attributie: als de registratie via een uitgedeelde link binnenkomt
        // (frontend stuurt `utm_source` mee als share_uuid van de ambassadeur),
        // koppelen we 'm hier. Onbekende of eigen-uuid wordt stil overgeslagen.
        $referredById = null;
        $utm = $request->input('utm_source');
        if (is_string($utm) && preg_match('/^[0-9a-f-]{36}$/i', $utm)) {
            $referrer = User::where('share_uuid', strtolower($utm))->first(['id']);
            if ($referrer) $referredById = $referrer->id;
        }

        // Create the user. Elk nieuw (gratis) account start met 7 dagen volledige
        // toegang: `trial_ends_at` op nu+7 dagen. Daarna valt het terug op het
        // gratis Starter-niveau tot er een abonnement wordt genomen (zie
        // User::hasPremiumAccess() + RequiresPremium-middleware).
        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'referred_by_id' => $referredById,
            'first_name' => $request->input('first_name'),
            'last_name'  => $request->input('last_name'),
            'trial_ends_at' => now()->addDays(User::APP_TRIAL_DAYS),
        ]);

        // Maak direct een Stripe-customer aan zodat de gebruiker al in Stripe
        // staat voor toekomstige facturatie (het "€0-abonnement" dat de klant
        // bij aanmelden aangaat). Best-effort: een Stripe-storing mag de gratis
        // registratie nooit blokkeren — de customer wordt anders alsnog lui
        // aangemaakt bij de eerste checkout.
        $this->ensureStripeCustomer($user);

        // Partner-attributie: kwam de registratie via een partner-referral-link
        // binnen (frontend stuurt `referral_code`, bv. JANSEN25), koppel de
        // gebruiker dan aan die partner. De partnerkorting gaat automatisch op
        // de eerste checkout; onbekende codes worden stil genegeerd.
        if ($request->filled('referral_code')) {
            app(\App\Services\PartnerService::class)
                ->attachReferral($user, $request->input('referral_code'));
        }

        // If this e-mail was invited to any mission/map, accept those invitations
        // now. Such accounts become free / view-only until they upgrade.
        $invitesAccepted = app(InvitationService::class)->convertForNewUser($user);
        $user->refresh();

        // If anyone invited this e-mail to chat, mark those invites accepted and
        // open a direct conversation so the inviter sees a "chat invite accepted"
        // event on their Hub timeline (and can jump straight into the chat).
        $this->acceptChatInvites($user);

        // Registratie-herkomst voor de admin-notificatie: de frontend stuurt de
        // pagina-URL (`source_url`) + externe verwijzer (`referrer`) mee. Valt
        // terug op de HTTP Referer-header als de client 't niet meestuurt
        // (oudere app-versies). Puur informatief; wordt niet opgeslagen.
        $sourceUrl = $request->input('source_url') ?: $request->header('Referer');
        $referrer  = $request->input('referrer');

        // Admin-notificatie "nieuwe gebruiker" — mag de registratie nooit
        // breken als de mailserver hapert. Stil loggen en doorgaan.
        try {
            Mail::to('hesselinggianni@gmail.com')->send(new NewUserRegistered($user, $sourceUrl, $referrer));
        } catch (\Throwable $e) {
            Log::warning('[register] kon admin-notificatie niet versturen: ' . $e->getMessage());
        }

        // Verificatiemail: gratis accounts moeten hun e-mail bevestigen (nep-account
        // weren). Bij guest-checkout/betaling wordt het account elders automatisch
        // geverifieerd, dus daar is dit niet nodig. Faalt nooit hard.
        \App\Services\EmailVerificationService::send($user);

        // Onboarding-funnel: plaats de nieuwe gebruiker automatisch in elke
        // campagne die als auto-enroll is gemarkeerd (dag-0-mail nu, vervolgmails
        // via de follow-up-engine). Best-effort — mag de registratie nooit breken.
        try {
            app(\App\Services\CampaignEnrollmentService::class)->enrollNewUser($user);
        } catch (\Throwable $e) {
            Log::warning('[register] funnel-enrollment mislukt: ' . $e->getMessage());
        }

        // Kwam dit e-mailadres eerder binnen als lead (bv. via de start-funnel
        // zonder de registratie af te maken)? Markeer 'm als geconverteerd —
        // stopt de lead-nurture-mails (account afmaken / waarom MilMap).
        try {
            \App\Models\Lead::markConverted($user->email);
        } catch (\Throwable $e) {
            Log::warning('[register] lead-conversie markeren mislukt: ' . $e->getMessage());
        }

        // Generate a Sanctum token scoped to the regular-app 'user' ability so
        // it can never satisfy tokenCan('admin') (admin routes require a token
        // minted through the admin login flow).
        $token = $user->createToken('API Token', ['user'])->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => array_merge($user->toArray(), [
                'verification' => $user->verificationState(),
                'premium'      => $user->premiumState(),
            ]),
            'token' => $token,
            'invites_accepted' => $invitesAccepted,
        ], 201);
    }

    /**
     * Zorg dat de gebruiker een Stripe-customer heeft (voor toekomstige
     * facturatie). Best-effort: faalt nooit hard — een ontbrekende/onbereikbare
     * Stripe-config mag de gratis registratie niet breken; de customer wordt dan
     * later alsnog lui aangemaakt bij de eerste betaalde checkout.
     */
    protected function ensureStripeCustomer(User $user): void
    {
        if ($user->stripe_id || ! config('billing.stripe_secret')) {
            return;
        }

        try {
            $stripe = new \Stripe\StripeClient(config('billing.stripe_secret'));
            $customer = $stripe->customers->create([
                'email'    => $user->email,
                'name'     => trim("{$user->first_name} {$user->last_name}"),
                'metadata' => ['user_id' => $user->id, 'signup' => 'free'],
            ]);
            $user->forceFill(['stripe_id' => $customer->id])->save();
        } catch (\Throwable $e) {
            Log::warning('[register] kon Stripe-customer niet aanmaken: ' . $e->getMessage());
        }
    }

    /**
     * Mark every pending chat invite for this user's e-mail as accepted, and
     * make sure a direct conversation exists between each inviter and the new
     * user. Failures are logged but never block registration.
     */
    protected function acceptChatInvites(User $user): void
    {
        $email = mb_strtolower($user->email);

        $invites = ChatInvite::where('email', $email)
            ->where('status', 'pending')
            ->get();

        foreach ($invites as $invite) {
            try {
                $this->ensureDirectConversation((int) $invite->inviter_id, (int) $user->id);

                $invite->update([
                    'status'           => 'accepted',
                    'accepted_user_id' => $user->id,
                    'accepted_at'      => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[chat] accept invite on register failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Find-or-create a 1-on-1 conversation between two users.
     */
    protected function ensureDirectConversation(int $a, int $b): void
    {
        if ($a === $b) {
            return;
        }

        $exists = Conversation::where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->where('users.id', $a))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $b))
            ->exists();

        if ($exists) {
            return;
        }

        $conversation = Conversation::create([
            'type'       => 'direct',
            'created_by' => $a,
        ]);
        $conversation->participants()->attach([$a, $b]);
    }
}
