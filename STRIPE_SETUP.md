# Stripe Billing — Setup Instructies

## 1. Installeer stripe-php via Composer

```bash
cd /Applications/MAMP/htdocs/milmap-backend
composer require stripe/stripe-php
```

## 2. Voer de migraties uit

```bash
php artisan migrate
```

Dit maakt drie nieuwe tabellen aan:
- `subscriptions`
- `subscription_items`
- Voegt kolommen toe aan `users`: `stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`

## 3. Stripe Dashboard inrichten

### a) API Keys ophalen
1. Ga naar https://dashboard.stripe.com/apikeys
2. Kopieer **Publishable key** → `STRIPE_KEY`
3. Kopieer **Secret key** → `STRIPE_SECRET`

### b) Producten en prijzen aanmaken
1. Ga naar https://dashboard.stripe.com/products
2. Maak twee producten: **MilMap Pro** en **MilMap Team**
3. Voeg vier prijzen toe:
   - Pro Maandelijks: €9,00/month
   - Pro Jaarlijks: €84,00/year
   - Team Maandelijks: €29,00/month
   - Team Jaarlijks: €276,00/year
4. Kopieer de Price IDs (beginnen met `price_`) naar `.env`

### c) Webhook instellen
1. Ga naar https://dashboard.stripe.com/webhooks
2. Klik **Add endpoint**
3. URL: `https://milmap.nl/api/v1/billing/webhook` (productie) of gebruik `stripe listen` lokaal
4. Selecteer deze events:
   - `checkout.session.completed`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.payment_failed`
5. Kopieer de **Signing secret** → `STRIPE_WEBHOOK_SECRET`

### d) Billing Portal inschakelen (voor abonnementsbeheer)
1. Ga naar https://dashboard.stripe.com/settings/billing/portal
2. Schakel de portal in
3. Pas eventueel de branding aan

## 4. .env invullen

```env
STRIPE_KEY=pk_test_xxxxxxxx
STRIPE_SECRET=sk_test_xxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxx

STRIPE_PRICE_PRO_MONTHLY=price_xxxxxxxx
STRIPE_PRICE_PRO_YEARLY=price_xxxxxxxx
STRIPE_PRICE_TEAM_MONTHLY=price_xxxxxxxx
STRIPE_PRICE_TEAM_YEARLY=price_xxxxxxxx
```

## 5. Lokaal testen met Stripe CLI

```bash
# Installeer Stripe CLI: https://stripe.com/docs/stripe-cli
stripe listen --forward-to http://localhost:8000/api/v1/billing/webhook

# Simuleer een betaling
stripe trigger checkout.session.completed
```

## 6. CORS / Sanctum

Zorg dat `https://milmap.nl` en `http://localhost:3000` in `SANCTUM_STATEFUL_DOMAINS` en `CORS_ALLOW_ORIGINS` staan voor authenticated checkout calls.

## API Endpoints

| Method | URL | Auth | Beschrijving |
|--------|-----|------|-------------|
| GET | `/api/v1/billing/subscription` | ✅ | Huidig abonnement ophalen |
| POST | `/api/v1/billing/checkout` | ✅ | Checkout sessie aanmaken |
| POST | `/api/v1/billing/portal` | ✅ | Billing portal sessie |
| GET | `/api/v1/billing/session?session_id=xxx` | ✅ | Betaling verifiëren |
| POST | `/api/v1/billing/webhook` | ❌ (Stripe-signed) | Stripe webhook |

## Frontend Pagina's

| Route | Beschrijving |
|-------|-------------|
| `/pricing` | Prijzenpagina met plan-selector |
| `/billing/success` | Redirect na geslaagde betaling |
| `/billing/cancel` | Redirect na geannuleerde betaling |
