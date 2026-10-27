# Customer readiness audit — October 28, 2026

**Verdict: incomplete; the current customer module is not ready for real customer operation.** The earlier passing-suite result was too broad a basis for readiness language. This audit adds negative tests and exposes open defects. No completion percentage is supported by the plan.

Scope: local `customer` branch, source/schema/configuration review and isolated executable service/browser scenarios. The separate seller/admin development branches were not merged, audited or changed. Building those modules separately remains the team's workflow; compatibility with them is unverified. Existing personal accounts and purchases were not changed by these tests.

## Confirmed defects still open

| Priority | Defect and reproduction | Actual result | Required behavior |
| --- | --- | --- | --- |
| P1 | Quote a product at ₱100, change its server price to ₱200, then submit checkout | Checkout creates the ₱200 order without price-change reconfirmation | Bind submission to a reviewed quote/version or return a changed quote for explicit review. Never trust a client-supplied total as the fix. Payment redirect currently follows successful order creation when configured, so this needs resolution before provider acceptance. No actual charge occurred in this audit. |
| P2 | End the authenticated server session independently, then open Account in the existing tab | Server session is signed out; client still has a user, shows cached account content and does not show sign-in | Handle authentication failures centrally: clear private client state and prompt sign-in. Protected server requests remain blocked; the reproduced issue is stale identity/display, not an authorization bypass. |
| P2 | Leave one unread seller reply before 60 newer customer messages; load Account | Server unread count is 1; header/client unread count is 0 | Use the server's complete unread count instead of counting only the latest loaded message page. |
| P2 | Load older messages, then run the same conversation refresh used by polling | Loaded message count drops from 63 to 50 | Merge refreshed messages with retained pagination history while the customer reads the thread. |
| P2 | Type a message draft, then trigger the tab-return visibility refresh | The message input becomes empty | Preserve drafts during refresh/render, per conversation. Existing polling skips active drafts, but the visibility refresh does not. |

Evidence locations in source:

- `app/Customer/OrderService.php`, checkout's fresh quote followed directly by INSERT, without a reviewed quote/version comparison.
- `public/assets/js/customer/customer.js`, `routeChanged()` error handling, the `visibilitychange` listener and conversation interval.
- `public/assets/js/customer/customer-data.js`, `loadConversations()` replaces the array with only the newest page and discards the server thread unread total.
- `public/assets/ui/core/experience.js`, `unreadMessages()` counts the in-memory page only.

## Missing capabilities and integration gaps

| Area | What is actually present | What still lacks |
| --- | --- | --- |
| Email/verification | Private development log files, verification endpoints and an outbox worker | Configured real email delivery and successful inbox acceptance. Ordinary customer self-service verification cannot be claimed from tests that read private development mail files. |
| Payments | PayMongo test adapter, signed-state validation and fake-gateway tests | Configured sandbox credentials, a reachable signed webhook, and a completed real provider test purchase/reconciliation. Local `paymongo_test_configured` is false. |
| Delivery/pickup tracking | One order-level status/history; client synthesizes each seller's shipment from it and fixes method to delivery | Independent seller fulfillment, actual courier/tracking information and pickup state/display. Two sellers cannot currently progress independently in the customer model. |
| Refund results | Customer requests, evidence, appeals and case status | Verified monetary refund records/results and accurate customer payment/invoice totals afterward. `mapOrder()` fixes `refundAmount` to 0, and payment status/schema does not represent partial/full refunds. Management must execute authorized refunds; customer still needs to consume/display their real outcomes. |
| Invoice | A browser-generated order record, with a derived `INV-` identifier and order snapshots | A separately issued merchant invoice resource/identifier under agreed issuance rules. Removing HTML download has been done; issuance is a different unfinished requirement. |
| Seller application | Submit, inspect own status and resubmit requested information | Tested management decision, seller provisioning and role handoff. Customer application persistence alone is not an end-to-end approved seller workflow. |
| Messaging and returns across roles | Customer submission and shared records | Actual seller inbox/replies/review, participant evidence readers, management appeal decisions and combined acceptance. Browser tests substitute database fixtures for these external actors. |
| Abandoned checkout | Stock reservation and safe explicit cancellation | Agreed expiry policy plus a job for unpaid orders without provider sessions. An isolated order dated 2020 remained pending and retained its reserved unit after payment refresh. Existing reconciliation selects known provider sessions, not these orders. |
| Profile corrections | Name editing; registered phone/birthday displayed | Customer correction of primary phone and an agreed birthday-correction policy. A submitted phone/birthday patch leaves those values unchanged. Address phone editing is separate. |
| Recovery | Authenticated password change | Forgotten-password recovery remains disabled. Its exact policy was not defined by the role matrix; it remains an operational gap rather than a falsely completed feature. |
| Data-loading cost | Server pagination endpoints | UI pagination/lazy loading. Account refresh made 15 requests with just two orders and one thread; it loads all order pages, each order detail and each thread's newest messages. This demonstrates request fan-out, not a measured production throughput limit. |

The local database is structurally healthy (`quick_check=ok`, zero foreign-key violations, five migrations), the return window is seven days, and Apache blocks direct private-file URLs. These are useful controls, not evidence that the business workflows above are complete.

## Executable audit and evidence

Run from the project root:

```powershell
& E:\xampp\php\php.exe tests/customer/customer-audit.php
# With isolated headless Edge/Chromium CDP on port 9223:
$env:SALESYNC_ADVERSARIAL_QA='1'
node tests/customer/customer-ui.mjs
Remove-Item Env:SALESYNC_ADVERSARIAL_QA
```

Both commands deliberately return exit code **1 while the reproduced defects remain**. The service audit reports one failed price-change scenario, one passed ownership control and two observations. The browser audit reports four failed scenarios, one passed message HTML-escaping control and one request-count observation. The ordinary UI assertions continue to pass; that does not cancel out the adversarial failures.

Final recorded evidence:

- `storage/customer-audit-dcb19b86e2f8/results.json` — quote/price, unpaid stock and ownership evidence.
- `storage/customer-ui-tests-1793118892692/adversarial-audit.json` — browser expected/actual records.
- `storage/customer-ui-tests-1793118892692/results.json` — normal UI assertions plus explicit adversarial failures.

These ignored artifacts contain isolated fixture data, not changes to the personal live customer account. The reusable audit code is in `tests/customer/customer-audit.php` and `tests/customer/customer-audit.mjs`.

## Recommended fix order

1. Price-change reconfirmation, centralized expired-session handling, draft retention, complete unread counts and stable message pagination; convert each reproduced scenario into a passing regression test after its fix.
2. Abandoned-order expiry policy/job, refund-result representation, and independently progressing delivery/pickup contracts with the relevant module owners.
3. Real mail and PayMongo sandbox acceptance, merchant invoice issuance rules, profile/recovery policies and actual cross-role flows.
4. Paginated/lazy UI loading and explicit concurrency, load, accessibility and failure-recovery exercises. Those broader audits have not been completed by the existing small isolated suites.

This turn adds audit code and findings. The five reproduced application defects are **open**, and this report does not certify deployment readiness.
