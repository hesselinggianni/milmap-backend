# Security hardening – 18 September 2026

Technical controls for the highest-priority findings. This is not an ISO 27001
certification or a complete assessment of the organization's ISMS.

## Implemented

- Service worker v6 never caches authenticated requests or MilMap API responses.
  Activation removes old shell/API/runtime caches and preserves public tile packs.
  Both logout paths clear private HTTP caches and cached account hints.
- Server API responses default to private/no-store. Explicitly public anonymous
  responses can retain HTTP caching; bearer-authenticated responses cannot.
- Direct map viewers cannot update maps. The displayed effective role and update
  permission use the same policy. Independent editor grants are retained.
- User API tokens expire after seven days, including previously issued tokens via
  Sanctum's global expiry. Newly issued tokens also have explicit expires_at.
  Browser handoff sessions expire after one hour.
- Admin tokens require an explicit admin ability, an expires_at and a maximum
  age of eight hours. Wildcard, transient and legacy non-expiring admin tokens
  cannot access routes protected by AdminAuth.
- Admin email codes are bcrypt hashes. Reissuing invalidates older challenges.
  Verification locks the user and challenge inside a transaction, consumes the
  challenge and issues the token atomically. Account-based rate limits complement
  existing IP limits. Unknown/non-admin account responses are generic.
- Admin code messages bypass the general outgoing mail archive and link tracking.
- AuthServices logout revokes the server token before removing it locally. Offline
  revocation cannot be guaranteed; server expiry limits remaining exposure.
- Chat identity records are keyed by authenticated user ID. Legacy `self` keys are
  migrated only after a server-public-key match. Unmatched legacy material stays
  preserved but is never automatically used for a different account. Chat startup
  does not create a replacement identity when escrow verification is unavailable.
  Mission chat uses the reconciled account identity instead of publishing its own.

## Rollout impact

Deploy frontend and backend together, then clear Laravel configuration cache using
normal deployment procedures. No schema migration is required for these changes.
Do not run production migrations or delete existing records merely to test them.

Existing admin sessions must sign in again, and outstanding plaintext admin codes
must be requested again. User tokens older than seven days stop working. Saved
API responses no longer provide offline fallback; offline maps use their dedicated
local storage. Existing stored email archives are NOT retroactively sanitized.

## Verification

Backend tests use a fresh in-memory SQLite database with a minimal schema and never
migrate or truncate the configured development or production database:

    php vendor/bin/phpunit tests/Feature/SecurityHardeningTest.php

Frontend regression tests:

    node --test tests/security-hardening.test.mjs tests/performance-cache.test.mjs

Staging/device acceptance (not completed by these automated tests):

1. Log in as A, load private data, log out, log in as B, then disconnect network.
   No A data may be returned by service worker caches. Confirm v6 activated.
2. As viewer, directly attempt map, route and waypoint mutations. Expect 403 and
   unchanged records. Repeat with editor and with a revoked/pending invitation.
3. Submit the same admin code concurrently against the actual production database
   engine: only one response may issue a token. SQLite tests cover sequential reuse,
   not a concurrent MySQL transaction test.
4. Check code expiry, reissue, account/IP throttling, revoked and expired tokens.
   In multi-server deployments, rate-limit storage must be shared and atomic.
5. Switch chat accounts on iOS, Android and web; verify key separation, restoration
   for the original account, legacy migration and inability to overwrite the
   server key during network failure.
6. Verify actual HTTP cache headers on success/error responses and at the CDN.
   Exercise authenticated resource downloads and offline tile packs.

## Outstanding highest-priority work

These controls must not be claimed as implemented:

- Native Keychain/Keystore token and private-key storage; tokens remain JS-readable
  and scoped chat private keys remain in IndexedDB. Requires a native bridge,
  migration, new native builds and real-device validation, not only an OTA update.
- Web HttpOnly session migration and the associated CSRF/login/checkout design.
- Independent MFA (preferably phishing-resistant) for administrators, enrollment,
  recovery and revocation. Hardened email codes are still a single possession factor.
- Account separation/encryption and retention rules for all local maps, missions,
  outbox entries, attachments and exported Documents files. Chat key separation and
  HTTP cache cleanup do not resolve every persistent local data store.
- A classified-data/offline policy and a safe migration for legacy local data whose
  owner cannot be proven. Do not silently assign or delete that data.
- Sanitization/retention of historical email archives and security event logging.

ISO evidence still needed: approved scope, risk assessment, control owners,
Statement of Applicability, access reviews, incident and restore exercises,
supplier assessments and recorded acceptance of remaining risks.
