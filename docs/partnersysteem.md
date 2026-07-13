# Partnersysteem (partners.milmap.nl)

Affiliate-/partnersysteem: partners delen een unieke referral-link
(`app.milmap.nl/ref/CODE`), doorverwezen gebruikers krijgen korting op hun
abonnement, en partners ontvangen automatisch commissie via Stripe Connect.

## Architectuur

| Onderdeel | Locatie |
|---|---|
| Portaal (Vue 3 + Vuetify, Vite) | `htdocs/milmap/milmap-partners/` → partners.milmap.nl |
| Backend | `milmap-backend`: `Partner`/`PartnerReferral`/`PartnerCommission` models, `PartnerController` (publiek), `PartnerDashboardController` + `PartnerStripeController` (portaal), `AdminPartnerController` (admin), `PartnerService` (kernlogica) |
| Migratie | `2026_07_13_100000_create_partner_tables.php` |
| Admin-UI | `milmap-admin` → navitem "Partners" (`/partners`) |
| Referral-capture | `MilMap-Frontend`: route `/ref/:code` + `PartnerReferralCapture.js` + `AuthView.vue`/`Register.vue` sturen `referral_code` mee bij registratie |
| Payout-cron | `partners:payout` — maandelijks op de 1e om 08:00 (Kernel), ook handmatig via admin |

## Flow

1. Aanmelden op partners.milmap.nl (maakt zo nodig een milmap-account aan +
   wachtwoord-instelmail) → status `pending`, admin krijgt mail.
2. Admin keurt goed in admin.milmap.nl → partner krijgt mail met referral-link.
3. Partner koppelt bankrekening via Stripe Connect Express (portaal → Uitbetaling).
4. Gebruiker registreert via `/ref/CODE` → `partner_referrals`-koppeling met
   snapshot van commissie-/kortingspercentage.
5. Bij checkout van zo'n gebruiker gaat de partnerkorting automatisch op de
   Stripe-sessie (coupon `milmap-partner-<pct>`, duration=forever).
6. Elke betaalde factuur (`invoice.paid`-webhook) → pending commissie
   (idempotent op invoice-id). Refund (`charge.refunded`) → commissie terug-
   gedraaid zolang die niet is uitbetaald.
7. `partners:payout` betaalt per partner alle pending commissies in één
   Stripe-transfer uit (minimum €10, anders doorschuiven) + mail.

## Rates

- Default: 20% commissie, 10% korting (kolomdefaults op `partners`).
- Aanpasbaar per partner in de admin; bestaande referrals houden hun snapshot.

## Prod-checklist

- [ ] `php artisan migrate` (partner-tabellen)
- [ ] `.env`: `PARTNER_URL=https://partners.milmap.nl` (fallback is al goed)
- [ ] Stripe Dashboard: **Connect inschakelen** + platformprofiel invullen (Express)
- [ ] Webhook-events abonneren op het bestaande endpoint (`/api/v1/billing/webhook`):
      `invoice.paid`, `charge.refunded`, `account.updated`
      (Connect-events: zet "listen to events on connected accounts" aan voor `account.updated`)
- [ ] Subdomein partners.milmap.nl aanmaken in DirectAdmin (DNS + SSL)
- [ ] Deploy portaal: `./deploy.sh partners` (nieuw target, statisch net als admin)
- [ ] Backend + frontend + admin deployen zoals gebruikelijk
- [ ] Testmodus doorlopen: aanmelden → goedkeuren → registreren via /ref/CODE →
      checkout (korting zichtbaar?) → webhook → commissie → payout-command

## Let op

- milmap.nl (statische prerender) heeft géén `/ref/`-route; de deelbare link is
  bewust `app.milmap.nl/ref/CODE` (SPA, werkt altijd). `Partner::referralUrl()`
  bouwt die link.
- De bestaande share-attributie (`utm_source` → `referred_by_id`) staat hier
  volledig los van en blijft gewoon werken.
- Partners loggen in met hun gewone milmap-account; `/api/v1/partner/*` staat
  buiten de e-mailverificatie-wall zodat een pending partner zijn status ziet.
