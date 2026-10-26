# Customer UI integration — October 27, 2026

Imported from `OVERALL-UI` commit `cfdc7ccf21407f415a13725dedee696782b78397`, file `SaleSync_Backend_Handoff.zip` (SHA-256 `bb97908ec9b388190f997b0be7e9b7f3892649f65053b478c68fd82054d19842`). Work remains on `customer`; the source branch and other role branches are unchanged.

## Preserved presentation

`public/index.php` is the only customer HTML page. Original CSS, customer templates, icons and local photographs live in `public/assets/ui/`. Shared rendering helpers are included only where customer templates need them. Seller/admin entry pages, controllers, prototype persistence, local login, synthetic histories, housekeeping, mock payment outcomes and other role bootstraps are not loaded.

The customer source retains the coworker's layout, colors, responsive styles, dialogs, account navigation and product cards. Integration adjustments remove prototype-only claims, replace the unbacked flash-sale countdown with featured product cards, resolve asset paths, and disable unsupported controls. No alternative design framework or UI theme was introduced.

Original handoff notes and image credits are retained under `docs/ui-handoff/`. The handoff describes a broader frontend prototype; its suggested features are not automatically implemented backend functionality.

## Connection layers

| File | Purpose |
| --- | --- |
| `public/assets/js/api.js` | Same-origin JSON requests, session cookies and CSRF tokens |
| `customer-runtime.js` | Transient view state and template helper functions |
| `customer-data.js` | Server DTO to customer-template mapping; load real account/cart/orders/notifications |
| `customer-render.js` | Customer-only single-page routing and capability states |
| `customer.js` | Async form/button handlers; all business changes go through the PHP API |
| `customer-features.js` | Password, saved products, voucher selection, conversations, evidence, applications and appeals |

The renderer works with pesos for display because that is the supplied template convention. API prices, quotes and database money remain integer centavos. Checkout uses the server quote and the server recalculates again when creating the order. Client record edits cannot authenticate a user, update inventory, verify payment or save an order.

Only checkout retry metadata is saved in `sessionStorage`; authentication and business records do not use the prototype's localStorage keys. Compare choices, search/filter selections and cart checkboxes are temporary presentation state. An unresolved checkout preserves its original body/key and offers an explicit confirmation retry; it never silently creates a second payment.

## Working flows

- Register/sign in/out using server sessions; registration collects phone, birthday and structured delivery address, enforces minimum age 18 on the server, and saves a default Home address. One-hour email verification links and resend cooldown remain in place.
- Browse/search/filter the server catalogue, product/gallery/options, public stores and store collections derived from published products.
- Add different product options, edit quantities, delete cart items, select a subset, and Buy Now without purchasing unrelated cart items.
- Manage own profile name and saved addresses, including edit/delete/default; view the registered primary phone and birthday on the profile. Choose a checkout address without changing its default.
- Show a server quote, create an idempotent order, retain unchecked cart items, and snapshot the selected option/name/price.
- Use PayMongo hosted test checkout when configured; return to the same page and retrieve trusted payment status. No local Pay/Fail controls are loaded.
- Read own purchases/status/history, view the stored order document, safely cancel eligible unpaid orders, and buy available items again.
- Read/mark own notifications. Product comparison is a temporary read-only view of fetched public data.

## Final-plan feature connections

The seven customer features requested after the audit are now connected: authenticated password change, persistent saved products, claim/apply vouchers, customer/store conversations, return/refund request/evidence/appeal, seller application/status and saved email preferences. [The feature handoff](CUSTOMER-FEATURES.md) specifies their policies and cross-role contracts. Seller/admin decisions and role routes remain with their owners. Tracking and invoices still need shared integration details.

Following stores, extended profile demographics, password recovery specifics and product reviews are not explicitly defined by the final table; their presence in the prototype alone does not establish the final policy or scope.

Real PayMongo acceptance testing still needs your test key, enabled methods and signed webhook configuration. Actual email delivery needs a mail transport; development links are in `storage/mail/`. With payment credentials absent, checkout saves an unpaid order and explains that payment setup is pending; Pay Now is disabled.

## Catalogue and schema additions

Migration `002_customer_ui.sql` adds presentation JSON, server-controlled `product_variants`, a cart key of `(cart_id,product_id,variant_id)`, and immutable option snapshots on order items. Stock remains explicitly shared across a product's options; the server checks aggregate quantities and reserves them in one transaction. A later per-SKU inventory model requires a coordinated migration with the seller owner.

New seller products must create at least one product option (`std` / `As pictured` is valid). Option price differences are stored in integer centavos. Presentation metadata never overrides the product's canonical price, status, stock, seller ID or option definitions.

`scripts/seed-ui-catalog.php` optionally imports the 141 visible catalogue fixtures with real server IDs and unguessable seller passwords. It creates no enabled demo-customer login and no fake sales. It is idempotent and does not overwrite existing prices, stock or records. `--archive-demo` hides only the earlier starter fixtures from `demo-store@example.test`; historical IDs remain valid.

To prepare the presentation again from a newer handoff directory:

```powershell
node scripts/prepare-customer-ui.mjs storage/ui-handoff/extracted/SaleSync_Online_v4
```

The preparation script evaluates only catalogue-building code inside an isolated JavaScript context and copies a curated file list. It does not run the handoff's build/deploy scripts or load its mock account/session/payment runtime into the connected UI.

## Validation

```powershell
& E:\xampp\php\php.exe tests/run.php
& E:\xampp\php\php.exe tests/customer-features.php
node tests/http.mjs
node tests/customer-ui.mjs
```

The browser suite needs a separate headless Chromium/Edge instance with remote debugging on `127.0.0.1:9223` (`CDP_URL` can override). Launch it with a dedicated user-data directory; do not attach tests to your personal browsing session. It creates its own temporary page, PHP server and database, then closes the test page/server. It never contacts PayMongo.

Browser coverage includes all main customer routes, actual registration/verification, selected product options, saved addresses, selected-only checkout, server persistence across reload, cancellation/stock restoration, profile edits, order document, notifications and existing-account login. It checks missing assets, uncaught exceptions and horizontal overflow at 390px. Screenshots/results are written to ignored `storage/customer-ui-tests-*/`.
