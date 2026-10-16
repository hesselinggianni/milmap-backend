# Connect management, seat orders and Android Enterprise

## Rollout

Deploy the backend migrations before the updated API, Connect frontend and native app. New schema is introduced by `2026_10_16_120000_add_connect_management`, `2026_10_16_130000_create_connect_orders` and `2026_10_16_140000_add_connect_device_platform`, in addition to the device-binding migration. Run the normal migration process against the intended environment; these changes have only been tested against an isolated SQLite test database here.

The historical map-reference migration now uses SQLite's native JSON expression for test installations; the MySQL expression is unchanged.

## Central policy

`ConnectManagement` supplies the authenticated user's `connect_management` snapshot. It is included in login, handoff and `/users/me`, and authenticated API responses carry the current snapshot in `X-Milmap-Management`. This header is for the app's same-origin/API client and must be exposed by the API CORS configuration when called cross-origin.

The backend middleware and organisation scope enforce managed identity, account deletion, personal billing/team restrictions, create/edit/delete permissions, internal/external sharing, public links, exports and integrations. Organisation permissions form the maximum allowed capabilities; individual members may restrict them further. Existing public links also respect current organisation policy. Connect manager endpoints always perform their own tenant and owner/admin checks. Existing resource-owner and collaborator permissions continue to apply: belonging to an organisation does not grant every member access to every map.

Default permissions: create/edit/internal sharing allowed; deletion/external sharing/public links/exports/personal integrations blocked. Default offline access is 24 hours, configurable from 0 to 168 hours. The backend bounds the deadline by the licence and API-token expiry. The app checks expiry continuously and on resume, renews online, blocks cached work when expired/revoked, and rechecks queued writes before replay. Preference changes remain available.

Offline devices cannot receive instantaneous server changes: an existing offline grant lasts until its previously issued deadline. Local app checks are not remote wipe or protection against a compromised OS or modified client. Previously exported files cannot be recalled. Use the organisation's MDM to revoke the work profile or wipe app data when required. The app blocks new automatic file mirrors, widgets/watch integration and exports according to policy.

## Ownership and handover

Maps, route maps and missions retain an immutable `connect_organization_id`. New work created in a managed session belongs to that organisation. The migration claims existing device-account data only where the allowed-device registry unambiguously identifies its organisation; it does not claim personal members' historical private data.

Managers can inspect retained work metadata and transfer all organisation-owned maps, route maps and missions, including trash, from a current/former member to an active member of the same organisation. Personal and foreign-tenant data is excluded. An account owning organisation data cannot be deleted until that data is transferred. Removing a member or device does not delete the work.

Managed local records are scoped by organisation and device account. Local-only workspace switching is disabled for managed accounts; offline mission drafts retain a cloud-sync intent. Legacy untagged local records are not automatically assigned to an organisation. Unsynchronised work is necessarily unavailable on another device until uploaded.

Device accounts identify an installation, not a verified employee. An administrator may assign an employee/shift label. Audit events record account, device and the assignment at the time; a label is not proof of the physical operator's identity. Audit payloads exclude passwords, bindings, access tokens, file contents and coordinates.

## Seats, subscriptions and invoices

Connect's licence page links to the existing app host at `/checkout/connect?org=<id>`. A short-lived one-use auth handoff carries the manager to the app checkout; the credential is removed from browser history immediately. Workspace roles are checked again by the API.

Use the existing MilMap Stripe account and the configured `connect_seat_monthly` price mapping. It must be an active, fixed recurring monthly price. The server fetches the price; client totals cannot set the amount. Supported invoice intervals are 1, 6 and 12 months. With no configured term discount, the period price equals the monthly unit price × interval × seats. Subscriptions automatically renew at the selected interval. The checkout states that recurring commitment explicitly.

- Direct payment creates a Stripe subscription Checkout Session. Stripe creates its invoice.
- Invoice payment creates a `send_invoice` subscription with a 14-day payment term, finalises/sends its first invoice and stores its hosted payment URL/PDF.
- The workspace's Stripe customer belongs to the organisation rather than a personal account. Billing details are encrypted at rest on the order.
- Orders and invoices are available in Connect; a manager can stop renewal there. Already paid capacity remains available until its paid period ends.
- Capacity is granted only on a verified `invoice.paid` webhook with a paid status and valid billing period. Checkout completion alone never grants seats. Capacity is keyed by Stripe invoice ID, so retries do not grant duplicates. Renewal invoices grant the next paid period separately.
- Requests and Stripe operations use stable idempotency keys. A changed quoted price requires a fresh checkout confirmation. Stripe is never called by the automated tests: they use a fake client.

Stripe webhook endpoint: retain signature verification and subscribe to `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `invoice.finalized`, `invoice.voided`, `customer.subscription.updated`, `customer.subscription.deleted`. Set `CONNECT_URL` and `FRONTEND_URL` to the intended hosts. Stripe Tax/tax registration and offered payment methods remain those of the existing Stripe setup; no live prices, subscriptions or payments were created during development.

## Android Enterprise

Enrol the device as fully managed or use an Android Enterprise work profile via the customer's MDM (for example Intune). For each enrolled device:

1. Register a stable per-device inventory identifier via `POST /api/v1/connect/mdm/devices` with `platform: android`, `udid: <inventory-id>`, `name: <device-name>`. The legacy API field is called `udid` for both platforms; it is an inventory identifier, not an authentication credential. Allowed characters: 8–64 letters, digits or hyphens. For MDM identifiers outside that format, use a stable hash of tenant + enrollment identifier in the integration.
2. Request `POST /api/v1/connect/mdm/devices/<inventory-id>/binding` using the organisation MDM token. The returned random `device_binding` is unique to that device. Keep the organisation token in the integration, never in the app.
3. Deliver `device_binding` as MilMap's managed app configuration via Managed Google Play/MDM. The Android app declares its schema in `app_restrictions.xml` and reads it using `RestrictionsManager` through `MilMapManagedConfigPlugin`.
4. The existing backend binding login verifies licence, organisation membership and installation registration. Android account email identifiers are tenant-specific hashes; neither a claimed Android ID nor a claimed organisation ID grants access.
5. Changes/removal of managed configuration are detected on resume and via the system restrictions-changed broadcast. The old local session is blocked and the app returns to device login. Revoke the server binding/device as part of unenrolment, as on Apple.

The MDM must set a **different binding per device**. Sharing one secret in an organisation-wide static configuration defeats individual device management. This is MDM-bound app authentication, not hardware attestation. A factory reset/re-enrolment requires the normal MDM reprovisioning flow.

Official references: https://developer.android.com/work/managed-configurations and https://docs.stripe.com/api/subscriptions/create.
