# SaleSync customer module

Customer module on the `customer` branch. PHP 8.2 + PDO SQLite; no Composer or npm packages required. The coworker's customer UI from `OVERALL-UI` is connected to the backend through one entry point, `public/index.php`. It retains their styles and customer templates; server APIs replace the prototype's local authentication, cart, order and payment mutations.

## Start with XAMPP

From this repository in PowerShell:

```powershell
if (-not (Test-Path config/local.php)) { Copy-Item config/example.php config/local.php }
& E:\xampp\php\php.exe scripts/migrate.php
& E:\xampp\php\php.exe scripts/seed-ui-catalog.php --archive-demo
```

Start Apache in XAMPP and open `http://localhost/FINAL_SaleSync/public/`. Register your own customer in the UI. The UI seed imports the handoff's 141 visible sample products, options, photos and 29 approved stores; it imports no prototype customer accounts, credentials, orders or paid events. `--archive-demo` reversibly hides only the earlier 12 `DEMO-*` fixtures from our demo store; it preserves their IDs and any order snapshots. Omit it to retain those listings. Existing non-demo records are left alone. Running `scripts/seed.php` remains an alternative for backend-only tests.

New customers register with a phone number, birthday and delivery address (house/building/unit number, street, barangay, city, province/region and postal code). The minimum age is 18, enforced on the server. Registration automatically saves a default Home address in My Addresses and stores phone/birthday in the profile. Run migrations after updating so `003_registration_details.sql` is applied; existing accounts remain available.

Province/Region → City/Municipality → Barangay now uses linked dropdowns backed by the [bundled Philippine location directory](docs/PHILIPPINE-LOCATIONS.md): 82 provinces, 18 regions, 1,642 cities/municipalities and 42,010 barangays. Runtime lookups stay local; registration checks both parent relationships on the server. Metro Manila and Manila districts are handled without an extra address step.

The same dropdowns work in Add/Edit Address and when adding an address during checkout. Migration `004_structured_addresses.sql` saves location codes and house/street/postal details for automatic edit prefill. Older full-text addresses are preserved and shown for reference when converting them to the structured form.

Alternatively, set `app_url` in `config/local.php` to `http://127.0.0.1:8080` and run:

```powershell
& E:\xampp\php\php.exe -S 127.0.0.1:8080 -t public scripts/serve.php
```

Use `public/` as the document root when setting up a virtual host. The root `.htaccess` also blocks private directories and the planning PDF when Apache serves the repository under `htdocs`; it requires `AllowOverride` and `mod_rewrite`. The frontend client uses the query-string API form so it does not depend on API URL rewrites. Never expose the repository root on a server that ignores `.htaccess`.

## Clean structure

```text
app/
  Auth/              Customer authentication and verification mail
  Core/              Database, sessions, validation and API errors
  Customer/          Catalogue, cart, addresses, orders, notifications
  Http/              API routing and authorization boundary
  Payments/          PayMongo adapter, signed webhooks, payment transitions
config/
  example.php        Documented defaults
  local.php          Your local configuration (ignored by Git)
database/migrations/ Versioned shared schema
database/fixtures/   Optional customer UI catalogue fixture
docs/                Plan analysis and frontend API handoff
public/
  index.php          The ONE customer page
  api/               JSON API entry point
  assets/js/customer/ Customer API, data mapping, rendering and event bridge
  assets/ui/         Coworker's customer templates, styles and images
scripts/             CLI migrations, demo catalogue, payment reconciliation
storage/             Private SQLite database, sessions, mail, logs (ignored)
tests/               Shared service/payment and HTTP integration tests
  customer/          Dedicated customer feature and browser tests
```

Customer implementation is grouped in `app/Customer/`, `public/assets/js/customer/`, `public/assets/ui/customer/` and `tests/customer/`. Shared authentication, core services, payments, configuration and migrations remain available to all roles. `public/index.php` remains the single customer page and keeps its existing website URL.

## Frontend handoff

The [final role plan and customer checklist](docs/FINAL-ROLE-PLAN.md) are authoritative for scope. Password changes, saved products, vouchers, messaging, return/evidence/appeal submissions, seller applications and notification preferences are connected. See [the feature handoff](docs/CUSTOMER-FEATURES.md) for policies, API contracts and teammate responsibilities. Shared fulfillment, invoicing and external service acceptance remain integration work.

Read [the UI integration notes](docs/CUSTOMER-UI-INTEGRATION.md), [the API contract](docs/API.md), and [the module connection guide](docs/MODULE-INTEGRATION.md). Home, Shop, stores, cart, purchases, account, messages and notifications use sections/modals/hash navigation within `public/index.php`. The supplied styles are retained. Run migrations to apply `005_customer_features.sql`; existing databases receive a private backup before upgrading.

The customer component is composed through `SaleSync\Customer\Module`. A shared host can inject its existing database and payment gateway and mount the dispatcher in its router. Session identity is configurable and shared across role modules; shared login must still enforce each role's permissions. The connection guide defines table ownership, common IDs, stock reservations, payment states and merge acceptance checks.

## Email and payments

Development verification emails are JSON files in `storage/mail/`; open the verification link locally. The connected frontend verifies it and removes the token from the location. No token is returned by registration. For actual email delivery, configure PHP's mail transport and set `mail_driver` to `mail` with a legitimate `mail_from`.

Run `php scripts/send-notifications.php` to process queued account/order/message notices. Optional email respects customer preferences; in-app records remain. The default log driver writes development files, without real inbox delivery. Return requests use configurable `return_request_days` (default 7) after recorded delivery/completion. Vouchers come from shared seller/management offers; no live offers or fake decisions are seeded by this update.

Set `paymongo_secret` to your **test** secret (`sk_test_...`), `paymongo_webhook_secret` to your webhook signing secret, and `payment_methods` to methods enabled on your account in ignored `config/local.php`. Set `app_url` to the actual customer page base URL; use HTTPS and `secure_cookies=true` when publicly hosted. The endpoint accepts test signatures only.

Register `https://YOUR-HOST/api/webhooks/paymongo` (include your deployment subdirectory, if any) for `checkout_session.payment.paid`. The query form `/api/index.php?route=/webhooks/paymongo` also works. Localhost is not reachable by PayMongo; a public test server or tunnel is needed. Follow the official [Hosted Checkout guide](https://docs.paymongo.com/docs/payment-channels-hosted-checkout) and [signature verification guide](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management).

The backend creates v2 hosted checkout sessions and retrieves them through the Checkout Session retrieval API. A redirect never marks an order paid. Signed events or authenticated retrieval must match the stored order amount, PHP currency, test mode and session reference. Hosted payment returns to `#orders/{id}` on the single customer page.

Schedule `php scripts/reconcile-payments.php` to refresh known unpaid sessions. Expired sessions cancel unpaid orders and release stock once. Creation timeouts leave a protected `unknown` payment; look up its order number in the PayMongo dashboard, then run `php scripts/reconcile-payments.php cs_THE_SESSION_ID`. This verifies the provider record before recovering it. Do not manually clear unknown state or create another payment. Establish an abandoned-order expiry policy with the team; there is no automatic expiry job for orders with no provider session yet.

Real PayMongo requests and real inbox delivery still need configuration and acceptance testing. Automated payment tests use an explicit fake gateway; the application has no fake-payment-success endpoint.

## Verification

```powershell
& E:\xampp\php\php.exe tests/run.php
& E:\xampp\php\php.exe tests/customer/customer-features.php
node tests/http.mjs
# With a separate headless Chromium/Edge debugging endpoint on port 9223:
node tests/customer/customer-ui.mjs
```

The service tests cover ownership, stock, snapshots, checkout retries, payment authenticity, duplicate events, expiry and uncertain provider failures. HTTP tests start a temporary PHP server and exercise cookies, CSRF, verification, roles and the customer shopping flow. Both use separate databases under ignored `storage/`; HTTP tests never call PayMongo. Set `PHP_BINARY` if PHP is installed elsewhere.

Other module owners should reuse the shared schema and agree on the contract before merging. Seller/admin UI, reports, maintenance and historical sales fixtures are outside this customer branch's implementation.
