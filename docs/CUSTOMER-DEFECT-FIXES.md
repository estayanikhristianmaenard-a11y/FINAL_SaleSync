# Five customer defect fixes — October 28, 2026

The five defects from the adversarial readiness audit are fixed locally on `customer`. This resolves those defects; the separate external-service and cross-role gaps remain.

| Defect | Implementation | Verification |
| --- | --- | --- |
| Price changes without reconfirmation | Server-generated, customer-bound quote tokens persist a fingerprint of reviewed items, quantities, prices, shipping, discounts and vouchers. Checkout recomputes under its write transaction and rejects changed/expired quotes before reserving stock or creating an order. | The ₱100 → ₱200 case returns 409 with reason `changed`, zero new orders and unchanged stock. Browser shows the updated quote, stays at checkout and requires another Place Order click. |
| Expired-session handling | Central API handling for protected-request 401, inactive-role/account 403, expired-CSRF session checks and signed-out session responses. In-flight responses from an ended session are rejected. Private client collections, modal contents and drafts clear; sign-in is shown. | Auth-version revocation produces no server/client authentication, visible sign-in, no account panel, zero private rows and zero drafts. Existing API tests retain backend ownership protection. |
| Message drafts disappear | Drafts are captured in memory per thread before rendering and restored afterward. A successful send clears its draft; ending the authenticated session clears all drafts. | Draft survives visibility refresh and navigation away/back. Draft content is not persisted to localStorage. |
| Incorrect unread count | Customer threads carry the authoritative complete server unread total; header, thread badges and unread filtering use it. Successful read acknowledgement sets the local total to zero. Legacy template data without a server total retains its fallback count. | Server/client both report 1 while the newest page contains zero unread messages and older history exists. |
| Older history disappears | Conversation refresh merges messages by stable ID, refreshes the previously loaded range and retains the oldest-page state. Read receipts update without discarding loaded pages. | A loaded 63-message thread still contains 63 after refresh. |

## Checkout integration change

Run `php scripts/migrate.php` to apply `006_checkout_quotes.sql`. The supplied migration command backs up an existing database privately before upgrading. The current local database has been upgraded and passed integrity/foreign-key checks.

1. POST `/checkout/quote` with the intended selection and voucher IDs.
2. Show the returned authoritative items and totals. The response includes `quote_token` and `quote_expires_at`.
3. POST `/checkout` with the existing address/items/vouchers, **the reviewed `quote_token`**, and `Idempotency-Key`.
4. A 409 with `fields.quote` equal to `changed` or `expired` requires fetching/showing a fresh quote and explicit customer confirmation. The UI never resubmits automatically or redirects to payment after that rejection.

Tokens last 15 minutes, are stored as hashes and are bound to the customer. The backend still computes all prices; supplying a client total cannot change the amount. Other customers, fabricated tokens and expired tokens are rejected. A completed checkout retry still returns its original order before quote validation, preserving recovery even after the token expires or the cart is cleared. No stock is reserved by issuing a quote.

## Checks

```powershell
& E:\xampp\php\php.exe tests/run.php
& E:\xampp\php\php.exe tests/customer/customer-features.php
& E:\xampp\php\php.exe tests/customer/customer-audit.php
node tests/http.mjs
# Separate isolated Edge/Chromium CDP instance required on port 9223:
$env:SALESYNC_ADVERSARIAL_QA='1'
node tests/customer/customer-ui.mjs
Remove-Item Env:SALESYNC_ADVERSARIAL_QA
```

The audit continues to gate the actual failure cases. Its observations about abandoned unpaid reservations, name-only profile editing and request fan-out remain separate work; passing the five fixes does not relabel those observations as implemented.

Final run: 78 foundation/service, 64 feature/upgrade, 143 HTTP and 157 UI checks passed. The service audit passed price-change rejection and ownership controls. The browser audit passed all four original browser failures, draft navigation retention and message HTML escaping, with zero adversarial failures. Syntax checks passed for 41 PHP and 14 JavaScript files. The local database reports six migrations, `quick_check=ok` and zero foreign-key violations.

Evidence: `storage/customer-audit-53bd248ddb08/results.json` and `storage/customer-ui-tests-1793122395215/adversarial-audit.json`. These ignored files contain isolated test fixtures; the existing personal account was not used for business mutations.

Real inbox delivery, a real PayMongo sandbox acceptance purchase, per-seller fulfillment/pickup, refund-result totals, merchant invoice issuance and teammate-module acceptance remain unfinished as recorded in the readiness audit.
