# Connect management, seat orders and Android Enterprise

## Rollout

Apply the normal backend migration process before enabling the updated API, Connect frontend and native app. Existing installations also need the earlier Connect organisation, licence, allowed-device and MDM-token migrations from 14–15 October. The current implementation requires the following 16 October migrations; deploying only the original management and order migrations is insufficient:

| Migration | Schema or data effect |
| --- | --- |
| `2026_10_16_100000_add_connect_device_bindings` | Device accounts, per-device binding hashes and token binding fields. |
| `2026_10_16_120000_add_connect_management` | Organisation/member policy, employee assignment, retained resource ownership and audit events. |
| `2026_10_16_130000_create_connect_orders` | Organisation Stripe customer, orders and invoices. |
| `2026_10_16_140000_add_connect_device_platform` | iOS/Android platform on allowed devices. |
| `2026_10_16_150000_add_uuid_to_connect_organizations` | UUIDs for public organisation URLs. Numeric API IDs remain supported. |
| `2026_10_16_160000_add_stripe_price_to_connect_organizations` | Organisation-specific Stripe seat price. |
| `2026_10_16_170000_add_account_details_to_connect_organizations` | Contact and billing email. |
| `2026_10_16_180000_default_restrict_connect_devices` | Enables allowed-device restrictions for **all existing organisations**, as well as changing the default. |
| `2026_10_16_190000_add_trial_to_connect_licenses` | Trial licence marker. |
| `2026_10_16_200000_connect_auto_renew_and_product` | Order renewal choice and organisation Stripe product. |
| `2026_10_16_210000_add_device_login_mode`, `2026_10_16_220000_default_device_login_udid` | Adds iOS login mode, then sets **all existing organisations** and the default to `udid`. Android still requires a binding. |
| `2026_10_16_230000_add_vat_to_connect_orders` | Stored VAT rate, amount, inclusive total and calculation method. |
| `2026_10_16_240000_add_billing_details_to_connect_organizations` | Encrypted billing profile and Stripe customer synchronisation hash. |
| `2026_10_16_250000_add_data_reset_at_to_users` | Account-reset timestamp for clearing local snapshots and queued work. |
| `2026_10_16_260000_remove_web_connect_devices` | Removes historical browser device registrations and detaches their device-token references. |
| `2026_10_16_270000_create_connect_pending_additions` | Encrypted member/device additions awaiting seat payment. |
| `2026_10_17_090000_inherit_route_map_connect_ownership` | Aligns existing route ownership with the parent map, including soft-deleted maps. |

Review the restriction and iOS login-mode data changes before rollout; select `binding` again for organisations that require secret-based iOS login after the default-mode migration. Preserve the environment's `APP_KEY`: order billing details, organisation billing profiles and pending addition rows use Laravel encrypted casts.

Set the backend's `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `CONNECT_URL`, `FRONTEND_URL` and `BILLING_VAT_PERCENT` (default 21). Set `STRIPE_PRICE_CONNECT_SEAT_MONTHLY` or the Admin billing mapping unless each organisation has its own price. In the Connect frontend, `VITE_API_URL` is the API origin, without `/api/v1`; `VITE_APP_URL` is the app host used for checkout. Rebuild the relevant frontend and native bundles and refresh the backend configuration cache through the normal release process. The API CORS configuration already exposes `X-Milmap-Management`; retain that header and the allowed frontend/native origins when deploying.

This review did not deploy, run migrations against a local application/production database, configure an MDM or create live Stripe objects. Schema and backend behaviour were exercised in isolated SQLite tests. Production database compatibility and the installed migration state still require the normal release checks.

The historical map-reference migration now uses SQLite's native JSON expression for test installations; the MySQL expression is unchanged.

## Central policy

`ConnectManagement` supplies the authenticated user's `connect_management` snapshot. It is included in login, handoff and `/api/v1/users/me`; API responses processed by the management middleware carry the current snapshot as base64-encoded JSON in `X-Milmap-Management`.

The backend middleware and organisation scope enforce managed identity, account deletion, personal billing/team restrictions, create/edit/delete permissions, internal/external sharing, public links, exports, integrations, account reset and location sharing. Organisation permissions form the maximum allowed capabilities; individual members may restrict them further. Existing public links, including map location and route-map subresource reads using a share token, also check the current organisation's public-link policy and active licence. Connect manager endpoints always perform their own tenant and owner/admin checks. Existing resource-owner and collaborator permissions continue to apply: belonging to an organisation does not grant every member access to every map.

Default permissions: create/edit/internal sharing/account reset/location sharing allowed; deletion/external sharing/public links/exports/personal integrations blocked. Stopping location sharing does not require a delete or location-sharing permission. Default offline access is 24 hours, configurable from 0 to 168 hours. The backend bounds the deadline by the licence and API-token expiry. The app checks expiry continuously and on resume, renews online, blocks cached work when expired/revoked, and rechecks queued writes before replay. Preference changes remain available.

Offline devices cannot receive instantaneous server changes: an existing offline grant lasts until its previously issued deadline. Local app checks are not remote wipe or protection against a compromised OS or modified client. Previously exported files cannot be recalled. Use the organisation's MDM to revoke the work profile or wipe app data when required. The app blocks new automatic file mirrors, widgets/watch integration and exports according to policy.

## Ownership and handover

Maps, route maps and missions retain an immutable `connect_organization_id`. New work created in a managed session belongs to that organisation. The migration claims existing device-account data only where the allowed-device registry unambiguously identifies its organisation; it does not claim personal members' historical private data.

A route map inherits its parent map's organisation even when created by an external collaborator or personal account. Moving it to a map in a different organisation is rejected. Policy checks use the parent map for legacy route maps whose own ownership field is empty.

Managers can inspect retained work metadata and transfer all organisation-owned maps, route maps and missions, including trash, from a current/former member to an active member of the same organisation. Personal and foreign-tenant data is excluded. An account owning organisation data cannot be deleted until that data is transferred. Removing a member or device does not delete the work.

Managed local records are scoped by organisation and device account. Local-only workspace switching is disabled for managed accounts; offline mission drafts retain a cloud-sync intent. Legacy untagged local records are not automatically assigned to an organisation. Unsynchronised work is necessarily unavailable on another device until uploaded.

Device accounts identify an installation, not a verified employee. An administrator may assign an employee/shift label. Audit events record account, device and the assignment at the time; a label is not proof of the physical operator's identity. Audit payloads exclude passwords, bindings, access tokens, file contents and coordinates.

## Seats, subscriptions and invoices

Connect's licence page links to the existing app host at `/checkout/connect?org=<organisation-uuid>`. Optional `seats` and `pending` parameters carry a seat count and pending addition reference. A two-minute, one-use auth handoff carries the manager to the app checkout through `/api/v1/auth/handoff` and `/api/v1/auth/handoff/exchange`; the credential is consumed during app bootstrap and removed from browser history. Workspace roles are checked again by the API.

Use the existing MilMap Stripe account and the organisation's `stripe_price_id`, when set. Otherwise the Admin billing `connect_seat_monthly` override takes precedence over `STRIPE_PRICE_CONNECT_SEAT_MONTHLY`. An administrator can set or clear the organisation override with `PUT /api/v1/admin/users/{ownerUserId}/connect-organizations/{organisationId}/stripe-price` and `price_id`. The price must be active, fixed and recurring every month. The server fetches it; client totals cannot set the amount.

Supported invoice intervals are **1, 6 and 12 months**: month, half-year and year. There is no term discount; the period price equals the monthly unit price × interval × seats before the applicable VAT calculation. Stripe subscriptions use month/1, month/6 or year/1 recurrence. The checkout lets the customer choose automatic renewal or expiration after the selected period. The API's optional `auto_renew` defaults to `true`; `false` schedules cancellation at the first period end. The selected commitment and renewal choice are shown before ordering.

The active checkout uses the configured `BILLING_VAT_PERCENT` for every order. A Stripe price marked `inclusive` already contains VAT; `exclusive`/`unspecified` prices have VAT added. A saved Stripe tax rate lets the invoice show VAT separately; if Stripe tax-rate access is unavailable, the service falls back to a rounded gross price per seat. The server and checkout use the same rounding and reject a submitted unit price, currency or inclusive total that differs. Country/VIES helper classes in this repository are not connected to the active billing routes or order flow; they do not currently change VAT according to country or VAT number.

- Direct payment creates a Stripe subscription Checkout Session. Stripe creates its invoice.
- Invoice payment creates a `send_invoice` subscription with a 14-day payment term, finalises/sends its first invoice and stores its hosted payment URL/PDF.
- The workspace's Stripe customer belongs to the organisation rather than a personal account. Billing details are encrypted at rest on the order and organisation; the organisation's billing email is stored separately. Checkout and the Connect account save the billing profile and synchronise changes to the Stripe customer.
- The service creates an organisation-specific Stripe product when permitted, otherwise it uses the product of the configured seat price. The customer's seat price still comes from the server-selected monthly price.
- Orders and invoices are available in Connect; a manager can stop renewal there. Already paid capacity remains available until its paid period ends.
- Capacity is granted only on a verified `invoice.paid` webhook with a paid status and valid billing period. Checkout completion alone never grants seats. Capacity is keyed by Stripe invoice ID, so retries do not grant duplicates. Renewal invoices grant the next paid period separately.
- Order requests and Stripe creation operations use idempotency keys. A changed quoted price or total requires a fresh checkout confirmation. Billing tests use a fake Stripe client.

Manager billing routes live under `/api/v1/connect/organizations/{organisation}/billing`: `GET catalog`, `PUT details`, `GET/POST orders` and `POST orders/{order}/cancel`. All require an active owner/admin membership and check the order's organisation. Creating an order also requires an active organisation.

Configure the existing **`POST /api/v1/billing/webhook`** endpoint, preserving Stripe signature verification with `STRIPE_WEBHOOK_SECRET`. Subscribe to `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `invoice.finalized`, `invoice.voided`, `customer.subscription.updated` and `customer.subscription.deleted`, alongside events already needed for personal billing. Use the signing secret and prices from the same Stripe account/mode as `STRIPE_SECRET`. Checkout success redirects to `CONNECT_URL`; cancellation returns to `FRONTEND_URL`.

Connect Checkout currently lets Stripe's Dashboard configuration choose offered payment methods. Its service does not pass the separate `STRIPE_PAYMENT_METHOD_TYPES` setting used elsewhere in billing. Validate the required subscription-compatible methods and invoice emails in the intended Stripe test environment before release.

When a manager tries to add more members/devices than the free capacity, Connect saves an encrypted pending addition, then opens checkout for at least the missing seats. The pending addition is attached to the order via `pending_addition_id`. After a paid invoice, the webhook adds the seats and attempts the saved additions. Duplicate existing members/devices are skipped. A failed addition keeps the purchased capacity; the manager can retry or cancel the addition. Cancellation does not cancel the order or refund the seats.

Pending-addition routes are `POST /api/v1/connect/organizations/{organisation}/pending-additions`, `GET/DELETE .../pending-additions/{pendingAddition}` and `POST .../pending-additions/{pendingAddition}/retry`. Status polling can reconcile an already paid addition or recover processing stuck for more than ten minutes. Provisioning errors are reported separately from webhook acceptance, so check the pending status and retry failures even when Stripe reports successful delivery. Invitation email failures are reported in the completion result.

## Android Enterprise

Enrol the device as fully managed or use an Android Enterprise work profile via the customer's MDM (for example Intune). For each enrolled device:

1. Register a stable per-device inventory identifier via `POST /api/v1/connect/mdm/devices` with `platform: android`, `udid: <inventory-id>`, `name: <device-name>`. The legacy API field is called `udid` for both platforms; it is an inventory identifier, not an authentication credential. Allowed characters: 8–64 letters, digits or hyphens. For MDM identifiers outside that format, use a stable hash of tenant + enrollment identifier in the integration.
2. Request `POST /api/v1/connect/mdm/devices/<inventory-id>/binding` using the organisation MDM token. The returned random `device_binding` is unique to that device. Keep the organisation token in the integration, never in the app.
3. Deliver `device_binding` as MilMap's managed app configuration for `nl.milmap.app` via Managed Google Play/MDM. The Android app declares its schema in `app_restrictions.xml` and reads it using `RestrictionsManager` through `MilMapManagedConfigPlugin`. Install the native build containing this plugin; a JavaScript/OTA update alone cannot add it.
4. The existing backend binding login verifies licence, organisation membership and installation registration. Android account email identifiers are tenant-specific hashes; neither a claimed Android ID nor a claimed organisation ID grants access.
5. Before unlocking cached work on a cold start, the app compares fresh native managed configuration against the token-bound SHA-256 fingerprint in its server management snapshot. Android also checks on resume, focus/visibility and the system restrictions-changed broadcast. A changed/missing binding or missing cached fingerprint fails closed and clears the invalid session; no binding secret is persisted in JavaScript storage. Revoke the server binding/device as part of unenrolment, as on Apple, through `DELETE /api/v1/connect/mdm/devices/{inventory-id}`.

The MDM must set a **different binding per device**. Sharing one secret in an organisation-wide static configuration defeats individual device management. This is MDM-bound app authentication, not hardware attestation. A factory reset/re-enrolment requires the normal MDM reprovisioning flow.

The same binding fingerprint check applies to iOS binding sessions: the app reads the managed secret afresh and hashes it transiently in memory. iOS also has a separate `udid` mode, currently the organisation default, which intentionally uses the registered inventory ID rather than a secret. Select `binding` in Connect's iOS login settings to require the per-device secret. Android always requires `device_binding`, regardless of that iOS setting. See [connect-device-login.md](connect-device-login.md) for the iOS/Android provisioning guide.

## Verification and release checks

Automated coverage includes isolated backend management/billing tests, UI tests and mocked billing operations. Android Java compilation passed. These checks do **not** establish physical-device behaviour, MDM configuration delivery, a signed native store build or a real payment/invoice flow.

Before enabling the release for customers, exercise the intended Stripe test-mode checkout and invoice flow with signed webhook delivery: all three periods, renewal off/on, price/tax rounding, unpaid invoices granting no capacity, duplicate and out-of-order events, cancellation and pending additions including retry. On real managed iOS/Android devices verify configuration delivery, login, cold start and resume offline, binding removal/rotation, organisation revocation, policy expiry and tenant isolation. No physical-device or real-payment test was performed for this change.

Official references: [Android managed configurations](https://developer.android.com/work/managed-configurations) and [Stripe subscription creation](https://docs.stripe.com/api/subscriptions/create).
