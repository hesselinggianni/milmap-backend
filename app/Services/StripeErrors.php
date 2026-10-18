<?php

namespace App\Services;

use App\Models\StripeError;
use Illuminate\Support\Facades\Log;

/**
 * Legt Stripe-fouten vast voor het overzicht in MilMap Admin. Vastleggen mag nooit zelf een fout veroorzaken.
 * $handled = de flow ging verder ondanks de fout (bijv. een factuur die Stripe zelf afrondt).
 */
class StripeErrors
{
    public static function record(\Throwable $e, string $source, array $context = [], bool $handled = false): void
    {
        try {
            $api = $e instanceof \Stripe\Exception\ApiErrorException ? $e : null;
            $error = $api?->getError();
            $message = self::scrub((string) $e->getMessage());
            StripeError::create([
                'source' => $source, 'handled' => $handled,
                'http_status' => $api?->getHttpStatus(),
                'stripe_code' => $api?->getStripeCode() ?: ($error?->code ?? null),
                'stripe_type' => $error?->type ?? null, 'param' => $error?->param ?? null,
                'exception' => (new \ReflectionClass($e))->getShortName(), 'message' => $message,
                'missing_permission' => self::missingPermission($message),
                'stripe_request_id' => $api?->getRequestId(), 'context' => $context ?: null,
            ]);
            if (random_int(1, 50) === 1) StripeError::where('created_at', '<', now()->subDays(90))->delete();
        } catch (\Throwable $inner) {
            Log::warning('Stripe-fout kon niet worden vastgelegd', ['error' => $inner->getMessage()]);
        }
    }

    /** Stripe maskeert sleutels al, maar bewaar nooit meer dan het voorvoegsel. */
    public static function scrub(string $text): string
    {
        return preg_replace('/\b([srp]k_(?:live|test)_)[A-Za-z0-9]{6,}/', '$1…', $text);
    }

    /** "Enabling Invoices Write ('invoice_write') permissions on this key…" → invoice_write */
    public static function missingPermission(string $message): ?string
    {
        return preg_match("/\\('([a-z_]+)'\\) permissions?/", $message, $m) ? $m[1] : null;
    }

    /** Wat de beheerder eraan kan doen, in gewone taal. */
    public static function hint(?string $code, ?string $missingPermission): ?string
    {
        if ($missingPermission) return "De beperkte Stripe-sleutel mist het recht '{$missingPermission}'. Zet dat aan bij Stripe → Developers → API-sleutels → de sleutel bewerken.";
        return match ($code) {
            'more_permissions_required' => 'De beperkte Stripe-sleutel mist een recht; de volledige melding noemt welk.',
            'api_key_expired', 'invalid_api_key' => 'De Stripe-sleutel op de server is ongeldig of verlopen.',
            'rate_limit' => 'Stripe kreeg te veel verzoeken in korte tijd; het is meestal vanzelf weg.',
            'resource_missing' => 'Het object bestaat niet (meer) in Stripe.',
            'invoice_not_editable', 'invoice_no_customer_line_items' => 'Deze factuur kan in zijn huidige status niet meer worden gewijzigd.',
            default => null,
        };
    }
}
