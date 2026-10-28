# Customer API and one-page frontend handoff

Base: `public/api/index.php?route=/ENDPOINT`. With rewrites, `public/api/ENDPOINT` also works. The supplied JavaScript helper resolves this path relative to itself and works inside a XAMPP subdirectory.

**Reviewed checkout quote is now required:** `/checkout/quote` returns a customer-bound `quote_token` and `quote_expires_at` (15-minute validity). Include that token in `/checkout`. Changes to prices, quantities, shipping or discounts return HTTP 409 with `fields.quote=changed`; missing/expired/foreign tokens return 409 with `fields.quote=expired`. Show a new quote and require confirmation. Existing completed idempotent retries still return their order. See [the defect-fix handoff](CUSTOMER-DEFECT-FIXES.md).

The [customer feature API extension](CUSTOMER-FEATURES.md) documents password changes, saved products, voucher claims and checkout `voucher_ids`, conversations, private attachments, returns/appeals, applications and preferences. `/session` additionally returns `return_request_days`; order details include `order_item_id`, line `discount_amount`, merchandise/shipping discounts and redeemed vouchers. Password changes rotate the CSRF token.

Responses are JSON: `{ "data": ... }` on success (HTTP 200), or `{ "error": { "message": "...", "fields": {}, "request_id": "..." } }`. Unexpected errors may omit `fields`. Timestamps are UTC ISO 8601. Monetary fields are **integer centavos**, currency PHP. Display with `formatMoney()`; never calculate authoritative order totals in the browser.

GET `/session` creates the session cookie and returns `user`, `csrf_token`, `payment_configured`, `currency`, `shipping_fee`, `minimum_customer_age` and `registration_date` (today in Asia/Manila, `YYYY-MM-DD`). Registration age hints use this date/policy; the server independently validates eligibility. Send the cookie and `X-CSRF-Token` on every mutation, including login/register/logout and email verification. Successful login/register/logout rotates the token. `api.js` handles this. Payment webhooks use their provider signature instead of browser CSRF.

| Method | Endpoint | Access | Input / result |
| --- | --- | --- | --- |
| GET | `/session` | Public | Current session, CSRF and payment configuration state |
| POST | `/auth/register` | Public + CSRF | Registration object below; returns user, rotated CSRF, verification delivery state |
| POST | `/auth/login` | Public + CSRF | `{email,password}`; returns user and rotated CSRF |
| POST | `/auth/logout` | Session + CSRF | Clears authentication, returns rotated CSRF |
| POST | `/auth/verify-email` | Public + CSRF | `{token}`; one-hour, single-use verification link token |
| POST | `/auth/resend-verification` | Customer | One request/minute; queues another verification email |
| GET | `/products` | Public | `q,category_id,seller_id,min_price,max_price,sort,page,limit`; result `{items,page,limit,total}` |
| GET | `/products/{id}` | Public | Published product with category and store details |
| GET | `/categories` | Public | Category list |
| GET | `/stores/{id}` | Public | Approved public store name and description |
| GET | `/stores` | Public | Approved public stores and presentation metadata |
| GET | `/locations/provinces` | Public | All provinces/regions as `{items,source}` with PSGC codes |
| GET | `/locations/cities` | Public | `province_code`; city/municipality options belonging to that province/region |
| GET | `/locations/barangays` | Public | `city_code`; that city's/municipality's barangay options |
| GET | `/profile` | Customer | Current identity, phone, birthday and verification status |
| PATCH | `/profile` | Customer | `{name}`; email and permissions cannot be changed here |
| GET | `/addresses` | Customer | Own address list, default first |
| POST | `/addresses` | Customer | Structured address object below; returns saved address and location codes |
| PATCH | `/addresses/{id}` | Owner | Contact/default changes or full structured location; returns saved address |
| DELETE | `/addresses/{id}` | Owner | Deletes address; historical order snapshot retained |
| GET | `/cart` | Customer | Items, current prices/availability, subtotal, shipping fee and total |
| PUT | `/cart/items/{productId}` | Customer | `{quantity,variant_id}`; quantity 1–999; option defaults to `std`; sets absolute quantity, returns cart |
| DELETE | `/cart/items/{productId}` | Customer | JSON `{variant_id}` (default `std`); removes that option, returns cart |
| POST | `/checkout/quote` | Customer | Optional `{items:[{product_id,variant_id}]}`; returns current server quote without reserving stock |
| POST | `/checkout` | Verified customer | `{address_id,items?}` and `Idempotency-Key`; returns order; omitted items means the entire cart |
| GET | `/orders` | Customer | `page`; returns `{items,page,total}`, 20 per page |
| GET | `/orders/{id}` | Owner | Own order, item/address snapshots, payment and status history |
| POST | `/orders/{id}/payment` | Verified owner | Starts/reuses PayMongo session; returns `{checkout_url,status}` |
| POST | `/orders/{id}/refresh-payment` | Owner | Retrieves trusted provider state, returns updated order |
| POST | `/orders/{id}/cancel` | Owner | Only unpaid orders for which a provider session has never been requested |
| GET | `/notifications` | Customer | `page`; returns `{items,page,unread}`, 20 per page |
| PATCH | `/notifications/{id}/read` | Owner | Marks own notification read |
| POST | `/webhooks/paymongo` | Provider | Raw JSON + `Paymongo-Signature`; never call from frontend |

All customer mutations require CSRF, not just the public ones labeled above. Customers must be active; sellers and both admin roles cannot use customer endpoints. Protected resources use the signed-in user's ID, never a supplied `user_id`. Another customer's resource returns 404.

## Registration details and age eligibility

All fields below are required for new registrations:

```json
{
  "name": "Customer Name",
  "email": "customer@example.test",
  "password": "YourChosenPassword",
  "phone": "09171234567",
  "birth_date": "2000-05-14",
  "house_number": "123 / Unit 4B",
  "street": "Sample Street",
  "province": "1300000000",
  "city": "1381200000",
  "barangay": "1381200019",
  "postal_code": "1605"
}
```

Password: at least 10 characters and no more than 72 bytes. Birthday: a real `YYYY-MM-DD` date, not in the future; new customers must be **18 or older**, measured by their actual birthday in Asia/Manila. `minimum_customer_age` is the shared configurable policy and defaults to 18. Client-supplied age or eligibility flags are ignored.

Phone: exactly 11 digits, for example `09171234567`. Extra digits, spaces, letters and country-code prefixes are rejected. House/building/unit: up to 100 characters; street: 150. Province/region, city and barangay are required 10-digit PSGC strings selected through the linked lookups; the server verifies their ancestry and resolves canonical names. Postal code: four digits, retained as text so leading zeroes are preserved. See [the Philippine location directory](PHILIPPINE-LOCATIONS.md) for version, coverage, parent rules and existing-address behavior.

The server combines address components into the existing `addresses.address_text`, uses the full name as recipient, and saves the phone in that address. It creates one **default Home address** with the account/cart in the same transaction. Birthday and primary phone are account profile fields (`birth_date`, `phone`); birthday is not included in delivery address snapshots. The saved address is immediately available under My Addresses and at checkout.

Migration `003_registration_details.sql` adds nullable profile fields without inventing information for existing customers. Existing accounts can still sign in. Profile editing currently updates the name only; this registration change does not silently enable birthday/email/password changes.

Product sorting: `newest` (default), `price_asc`, `price_desc`, `name`. Price filters use centavos. Product pagination defaults to 20 and supports 1–100 per page. Published products from suspended sellers/unapproved stores are hidden. `stock_qty=0` products can be browsed but cannot be added to the cart.

Address object:

```json
{
  "label": "Home",
  "recipient_name": "Customer Name",
  "phone": "09123456789",
  "house_number": "123 / Unit 4B",
  "street": "Sample Street",
  "province": "1300000000",
  "city": "1381200000",
  "barangay": "1381200011",
  "postal_code": "1611",
  "is_default": true
}
```

Label: up to 40 characters; recipient: 100. Phone/location validation is the same as registration. Save up to 20 addresses. The first address becomes default automatically. A PATCH of `{is_default:true}` switches the default while retaining all saved components; omit `is_default` to retain its current value.

Creating an address, or changing any location component, requires the full house/street/province/city/barangay/postal-code set. The server composes `address_text` from verified names; a client-supplied address string cannot override it. Responses include `house_number`, `street`, `province_code`, `city_code`, `barangay_code` and `postal_code` for edit-form prefill. PATCH may update contact/label/default independently without rewriting the saved location. Ownership checks apply to every operation.

Migration `004_structured_addresses.sql` adds nullable components/codes and preserves existing full-text addresses. When editing an older address without codes, the UI shows its original text and requires the customer to select the location/enter its house-street details once. No codes or address parts are guessed. Existing orders retain their original snapshots.

## Connect the coworker's design

The coworker's customer UI is now connected within `public/index.php`. See [the integration notes](CUSTOMER-UI-INTEGRATION.md) for the source split and supported controls. `customer.js` starts the application and processes verification links. Existing templates and styles are under `public/assets/ui/`. Continue to use text-safe DOM rendering for API content.

Products now include server-controlled `variants` (ID, name, attributes, price delta in centavos) and separate `presentation` metadata. Cart rows include selected option/name/attributes. Product stock is shared across its options and validated in aggregate. Orders retain immutable option snapshots. Selected-item checkout removes only those cart rows; unchecked options remain.

```js
import { api, formatMoney } from './api.js';
import { verifyEmailFromLink } from './customer.js';

const session = await api('/session');
// At startup, process verification links and display the returned state/errors.
await verifyEmailFromLink();
const catalogue = await api('/products', {
  query: { q: 'tote', sort: 'price_asc' }
});
const cart = await api('/cart/items/1', {
  method: 'PUT', body: { quantity: 2 }
});
console.log(formatMoney(cart.total));
```

Checkout is deliberately split from external payment creation. First show the latest cart summary and selected address. Generate one UUID for that submission, keep it across retries, disable repeated submission while waiting, then:

```js
// Persist this key for the same checkout attempt, including after a network failure.
const retryKey = crypto.randomUUID();
const order = await api('/checkout', {
  method: 'POST', body: { address_id: selectedAddressId },
  idempotencyKey: retryKey
});
const payment = await api(`/orders/${order.order_id}/payment`, { method: 'POST' });
if (payment.status === 'paid') {
  // Render the confirmed order using the agreed design.
} else if (payment.checkout_url) {
  location.assign(payment.checkout_url);
}
```

On return to `#orders/{id}`, call `/orders/{id}/refresh-payment`, then render its state. Users can also return to My Orders and reopen their pending payment. Returning from hosted checkout does not cancel the order or prove payment. A 503 for missing credentials leaves a durable unpaid order, which can be paid after configuration or safely cancelled before any provider session is requested.

Use the same checkout key only for retries of the same order intent. Once checkout succeeds, clear the saved key before the next purchase. A repeated key returns the original order even if the cart/address has since changed. The API never silently re-prices a durable order.

## State and error handling

Order states: `pending_payment`, `processing`, `ready_for_shipment`, `shipped`, `completed`, `cancelled`. Payment states: `pending`, `creating`, `unknown`, `paid`, `expired`. `creating`/`unknown` mean reconciliation is needed; prevent a second payment attempt. A failed payment attempt on an active provider session may be retried through the same hosted URL.

| HTTP status | Frontend behavior |
| --- | --- |
| 400 / 415 | Check JSON request shape/content type |
| 401 | Prompt sign-in; guest catalogue remains available |
| 403 | Show verification/account-role restriction |
| 404 | Resource missing or inaccessible |
| 409 | Refresh cart/order; stock or payment state changed |
| 413 | Request is too large (64 KiB limit) |
| 419 | Refresh `/session`, then ask the user to retry; do not replay mutations automatically |
| 422 | Show validation fields/message |
| 429 | Show cooldown and allow a later retry |
| 502 / 503 | Keep the order visible and show provider/configuration error; never show fake payment success |
| 500 | Show generic retry message and request ID |

The browser client rejects failed requests as `ApiError` with `status` and `fields`. Render errors, empty lists and loading states in the coworker's components. There is no separate login, cart, profile or success HTML page.
