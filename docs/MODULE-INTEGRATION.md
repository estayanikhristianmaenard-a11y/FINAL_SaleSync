# Connect the customer module to the complete SaleSync system

This is the customer component of a **single PHP application with one shared SQLite database**. Git branches are workspaces for development; they are not four deployed applications and do not synchronize data. Merge module source code into the assembled application and point all role modules at the same configured database.

Contract version: **1**. This contract describes this branch's integration surface. The other module owners still need to adopt it; their code has not been supplied or tested here.

## Module boundaries

| Component | Owns | Connection to customer |
| --- | --- | --- |
| Customer | Own cart, addresses, checkout, own order views and notifications | `SaleSync\Customer\Module` and the documented JSON API |
| Seller | Store application/profile, published products, stock and permitted fulfillment updates | Shared `seller_profiles`, `products`, `order_items` and `orders` records |
| Management admin | Account/store approval, listing moderation, business oversight and reports | Shared account status, approval, publication, order and payment records |
| Maintenance admin | Configuration, integration health and recovery | One application configuration and database; no ordinary access to customer private data |
| Shared infrastructure | Identity/session, schema migrations, inventory/order consistency and payment verification | Reuse `app/Core`, shared tables and the payment service; designate one owner for each shared file during merging |

The customer component does not call seller/admin HTML pages and does not depend on their layout. Its catalogue reads the same published product records the seller component writes. Its order records are the records the seller/admin components read. Customer UI remains one page inside `public/index.php`.

## Plug into an existing PHP host

The module has one composition entry point. A host can supply its existing `Database` instance and a `Gateway` implementation instead of letting the module create its own. Services never run migrations or seed data on construction.

```php
<?php
$config = require '/absolute/path/to/FINAL_SaleSync/app/bootstrap.php';

$sharedDatabase = new SaleSync\Core\Database($config['database']);
$customer = new SaleSync\Customer\Module($config, $sharedDatabase);

// One router can delegate its /customer API mount to the customer dispatcher.
// Strip only the router mount prefix: /customer/products becomes /products.
// $customerPath is the host router's normalized path after stripping that prefix.
$api = new SaleSync\Http\Api($config, $customer);
$data = $api->dispatch($_SERVER['REQUEST_METHOD'], $customerPath, file_get_contents('php://input'));
// Serialize $data with the same {data: ...} / {error: ...} envelope as public/api/index.php.
```

The supplied standalone adapter remains `public/api/index.php`; existing frontend endpoints keep working. Host dispatch must happen before output, inside its error handler. `dispatch` uses standard PHP request globals for query parameters and headers. Follow `public/api/index.php` for the size limit, JSON responses and error mapping. Do not expose service methods directly as browser routes: authorization and CSRF checks are enforced by the dispatcher. Trusted in-process callers must apply the same checks.

If the final host mounts the JSON API elsewhere, change the `endpoint` URL in `public/assets/js/api.js` to that route. No customer business service needs to change for a URL or frontend design change.

## Agree on identity once

All role modules use:

- The same `users` table and `user_id` primary key.
- Role strings `customer`, `seller`, `admin_maintenance`, `admin_management`.
- Account status `active` or `suspended`.
- PHP session name `salesync`, the same session storage directory, cookie path and cookie security settings.
- `$_SESSION['user_id']` for the authenticated user, `csrf` for the current CSRF token, and `last_active` for idle expiry.

Use `Session::start($config)` once before output and `Session::login($userId)` after authenticating through the shared identity service. The customer adapter also accepts an already started host session. It still checks current role/status from the database on every protected request. It never trusts a role stored in a cookie or supplied by the browser.

`AuthService::login()` in this branch is the **customer login adapter**. It deliberately rejects seller/admin logins. A future central login controller must authenticate all supported roles and then set the same session identity; do not route every role through the customer login adapter or duplicate users. Customer registration always creates a customer.

Set `session_cookie_path` to the common application path if the role interfaces use different subdirectories. Set `session_directory` to one common private folder if module configurations are stored separately. Defaults derive both from the shared application configuration/database. Changing from the earlier `salesync_customer` cookie name requires signing in again.

## Agree on the data contract

Run `scripts/migrate.php` once against the shared configured database. The canonical schema is `database/migrations/001_customer.sql`. Add uniquely named later migrations; do not import four copies of the initial schema or copy local SQLite files between branches.

| Shared value | Required meaning |
| --- | --- |
| Product price, totals, shipping and payment amount | Integer PHP centavos; never floating point pesos |
| Seller availability | Seller account is active, store approval is `approved` |
| Product availability | Product status is `published`; stock is checked separately |
| Stock | Current unreserved quantity; customer checkout already subtracts reservations |
| Order/payment IDs | The database IDs assigned by the shared tables; retain `order_items.seller_id` |
| Address and item snapshots | Immutable values captured at checkout; later catalogue/address edits do not rewrite orders |
| Order status | `pending_payment`, `processing`, `ready_for_shipment`, `shipped`, `completed`, `cancelled` |
| Payment status | `pending`, `creating`, `unknown`, `paid`, `expired` |
| Timestamps | UTC ISO 8601 |

The seller module must **not decrement stock again** when accepting a paid order. A verified payment changes an already reserved order to `processing`. Releasing unpaid reservations is coordinated by `OrderService` and verified payment expiry. Browser input and redirects cannot set `paid`.

Seller fulfillment updates must check paid state and item ownership, apply an agreed transition, and write `order_status_history` plus an appropriate customer notification inside one transaction. A single order may contain items from multiple sellers, but the current schema has one order-level fulfillment status. Before implementing seller transitions, the team must decide aggregate status rules or add per-seller fulfillment records; one seller must never mark another seller's items completed.

Only one configured payment service and webhook should process a shared order. `Gateway` defines `ready()`, `create($order)` and `retrieve($reference)`; a replacement adapter must return the same normalized Checkout Session structure expected by `PaymentService`. Production code uses the PayMongo test adapter. Test fakes remain in `tests/`.

## Verify the connection

1. Merge the shared foundation and customer source into the integration branch; resolve shared-file ownership deliberately.
2. Configure all components to use the same database, sessions, role values and money units; run migrations once.
3. In the seller module, create an approved store and publish a product. Confirm it appears through customer `/products` without copying data.
4. Place a verified customer test order. Confirm seller/admin views see that same order and seller-owned items; confirm stock is reserved once.
5. Verify PayMongo test payment. Confirm the customer, seller and management views read the same paid record.
6. Suspend the store/customer via management. Confirm the customer catalogue/protected endpoints immediately respect the updated status.
7. Test the agreed multi-seller fulfillment rules and the one-page frontend with all real modules present.

The local automated tests prove the customer composition shares an injected database/gateway and preserves customer API behavior. Complete four-module integration cannot be claimed until the other components are available and these acceptance checks pass.
