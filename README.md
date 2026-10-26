# SaleSync customer module

Customer backend on the `customer` branch. PHP 8.2 + PDO SQLite; no Composer or npm packages required. The customer frontend has one entry point, `public/index.php`, ready for your coworker's design. It currently contains an empty `#app` mount, not a completed shopping interface.

## Start with XAMPP

From this repository in PowerShell:

```powershell
Copy-Item config/example.php config/local.php
& E:\xampp\php\php.exe scripts/migrate.php
& E:\xampp\php\php.exe scripts/seed.php
```

Start Apache in XAMPP and open `http://localhost/FINAL_SaleSync/public/`. Check `http://localhost/FINAL_SaleSync/public/api/index.php?route=/products` for the seeded JSON catalogue. The seed adds 12 demo products and an approved store; it creates no customer passwords. Register your own customer through the API/frontend.

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
docs/                Plan analysis and frontend API handoff
public/
  index.php          The ONE customer page
  api/               JSON API entry point
  assets/js/         Frontend API helper and integration hooks
scripts/             CLI migrations, demo catalogue, payment reconciliation
storage/             Private SQLite database, sessions, mail, logs (ignored)
tests/               Isolated service/payment and HTTP integration tests
```

## Frontend handoff

Read [the API contract](docs/API.md), [the module connection guide](docs/MODULE-INTEGRATION.md), and [the plan analysis](docs/PLAN-ANALYSIS.md). Build Home, Shop, Cart, My Orders and Profile within `public/index.php`, using sections/modals/hash navigation. Follow the coworker's visual design. Import `api` and `formatMoney` from `public/assets/js/api.js`; no backend secrets belong in JavaScript.

The customer component is composed through `SaleSync\Customer\Module`. A shared host can inject its existing database and payment gateway and mount the dispatcher in its router. Session identity is configurable and shared across role modules; shared login must still enforce each role's permissions. The connection guide defines table ownership, common IDs, stock reservations, payment states and merge acceptance checks.

## Email and payments

Development verification emails are JSON files in `storage/mail/`; read their verification link locally. No token is returned by the registration endpoint. The frontend must call `verifyEmailFromLink()` at startup and render the result. For actual email delivery, configure PHP's mail transport and set `mail_driver` to `mail` with a legitimate `mail_from`.

Set `paymongo_secret` to your **test** secret (`sk_test_...`), `paymongo_webhook_secret` to your webhook signing secret, and `payment_methods` to methods enabled on your account in ignored `config/local.php`. Set `app_url` to the actual customer page base URL; use HTTPS and `secure_cookies=true` when publicly hosted. The endpoint accepts test signatures only.

Register `https://YOUR-HOST/api/webhooks/paymongo` (include your deployment subdirectory, if any) for `checkout_session.payment.paid`. The query form `/api/index.php?route=/webhooks/paymongo` also works. Localhost is not reachable by PayMongo; a public test server or tunnel is needed. Follow the official [Hosted Checkout guide](https://docs.paymongo.com/docs/payment-channels-hosted-checkout) and [signature verification guide](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management).

The backend creates v2 hosted checkout sessions and retrieves them through the Checkout Session retrieval API. A redirect never marks an order paid. Signed events or authenticated retrieval must match the stored order amount, PHP currency, test mode and session reference. Hosted payment returns to `#orders/{id}` on the single customer page.

Schedule `php scripts/reconcile-payments.php` to refresh known unpaid sessions. Expired sessions cancel unpaid orders and release stock once. Creation timeouts leave a protected `unknown` payment; look up its order number in the PayMongo dashboard, then run `php scripts/reconcile-payments.php cs_THE_SESSION_ID`. This verifies the provider record before recovering it. Do not manually clear unknown state or create another payment. Establish an abandoned-order expiry policy with the team; there is no automatic expiry job for orders with no provider session yet.

Real PayMongo requests and real inbox delivery still need configuration and acceptance testing. Automated payment tests use an explicit fake gateway; the application has no fake-payment-success endpoint.

## Verification

```powershell
& E:\xampp\php\php.exe tests/run.php
node tests/http.mjs
```

The service tests cover ownership, stock, snapshots, checkout retries, payment authenticity, duplicate events, expiry and uncertain provider failures. HTTP tests start a temporary PHP server and exercise cookies, CSRF, verification, roles and the customer shopping flow. Both use separate databases under ignored `storage/`; HTTP tests never call PayMongo. Set `PHP_BINARY` if PHP is installed elsewhere.

Other module owners should reuse the shared schema and agree on the contract before merging. Seller/admin UI, reports, maintenance and historical sales fixtures are outside this customer branch's implementation.
