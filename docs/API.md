# Customer API and one-page frontend handoff

Base: `public/api/index.php?route=/ENDPOINT`. With rewrites, `public/api/ENDPOINT` also works. The supplied JavaScript helper resolves this path relative to itself and works inside a XAMPP subdirectory.

Responses are JSON: `{ "data": ... }` on success (HTTP 200), or `{ "error": { "message": "...", "fields": {}, "request_id": "..." } }`. Unexpected errors may omit `fields`. Timestamps are UTC ISO 8601. Monetary fields are **integer centavos**, currency PHP. Display with `formatMoney()`; never calculate authoritative order totals in the browser.

GET `/session` creates the session cookie and returns `user`, `csrf_token`, `payment_configured` and `currency`. Send the cookie and `X-CSRF-Token` on every mutation, including login/register/logout and email verification. Successful login/register/logout rotates the token. `api.js` handles this. Payment webhooks use their provider signature instead of browser CSRF.

| Method | Endpoint | Access | Input / result |
| --- | --- | --- | --- |
| GET | `/session` | Public | Current session, CSRF and payment configuration state |
| POST | `/auth/register` | Public + CSRF | `{name,email,password}`; password at least 10 characters, at most 72 bytes; returns user, rotated CSRF, verification delivery state |
| POST | `/auth/login` | Public + CSRF | `{email,password}`; returns user and rotated CSRF |
| POST | `/auth/logout` | Session + CSRF | Clears authentication, returns rotated CSRF |
| POST | `/auth/verify-email` | Public + CSRF | `{token}`; one-hour, single-use verification link token |
| POST | `/auth/resend-verification` | Customer | One request/minute; queues another verification email |
| GET | `/products` | Public | `q,category_id,seller_id,min_price,max_price,sort,page,limit`; result `{items,page,limit,total}` |
| GET | `/products/{id}` | Public | Published product with category and store details |
| GET | `/categories` | Public | Category list |
| GET | `/stores/{id}` | Public | Approved public store name and description |
| GET | `/profile` | Customer | Current identity and verification status |
| PATCH | `/profile` | Customer | `{name}`; email and permissions cannot be changed here |
| GET | `/addresses` | Customer | Own address list, default first |
| POST | `/addresses` | Customer | Full address object; returns created address |
| PATCH | `/addresses/{id}` | Owner | Full address object; returns saved address |
| DELETE | `/addresses/{id}` | Owner | Deletes address; historical order snapshot retained |
| GET | `/cart` | Customer | Items, current prices/availability, subtotal, shipping fee and total |
| PUT | `/cart/items/{productId}` | Customer | `{quantity}` 1–999; sets absolute quantity, returns cart |
| DELETE | `/cart/items/{productId}` | Customer | Removes product, returns cart |
| POST | `/checkout` | Verified customer | `{address_id}` and `Idempotency-Key` header; returns order |
| GET | `/orders` | Customer | `page`; returns `{items,page,total}`, 20 per page |
| GET | `/orders/{id}` | Owner | Own order, item/address snapshots, payment and status history |
| POST | `/orders/{id}/payment` | Verified owner | Starts/reuses PayMongo session; returns `{checkout_url,status}` |
| POST | `/orders/{id}/refresh-payment` | Owner | Retrieves trusted provider state, returns updated order |
| POST | `/orders/{id}/cancel` | Owner | Only unpaid orders for which a provider session has never been requested |
| GET | `/notifications` | Customer | `page`; returns `{items,page,unread}`, 20 per page |
| PATCH | `/notifications/{id}/read` | Owner | Marks own notification read |
| POST | `/webhooks/paymongo` | Provider | Raw JSON + `Paymongo-Signature`; never call from frontend |

All customer mutations require CSRF, not just the public ones labeled above. Customers must be active; sellers and both admin roles cannot use customer endpoints. Protected resources use the signed-in user's ID, never a supplied `user_id`. Another customer's resource returns 404.

Product sorting: `newest` (default), `price_asc`, `price_desc`, `name`. Price filters use centavos. Product pagination defaults to 20 and supports 1–100 per page. Published products from suspended sellers/unapproved stores are hidden. `stock_qty=0` products can be browsed but cannot be added to the cart.

Address object:

```json
{
  "label": "Home",
  "recipient_name": "Customer Name",
  "phone": "09123456789",
  "address_text": "House, Street, Barangay, City, Province, Postal Code",
  "is_default": true
}
```

Label: up to 40 characters, recipient: 100, phone: 7–25, address: 10–1000. Save up to 20 addresses. The first address becomes default automatically. Set `is_default:true` on another address to switch the default. Edits require all text fields; omit `is_default` to retain its current value.

## Connect the coworker's design

Keep the one HTML document in `public/index.php`. Render within `#app`. Connect all agreed components in `public/assets/js/customer.js`; the module exports a verification helper and imposes no styling. Use text-safe DOM rendering for API content.

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
