# Customer feature handoff

Implemented on `customer`, October 27, 2026. The customer remains a single page using the supplied UI. These contracts extend the shared SQLite schema through `005_customer_features.sql`; the other role owners still need to adopt them and run cross-role acceptance tests.

## Customer controls and endpoints

All JSON routes use the existing session, active-customer authorization and CSRF boundary. Mutations never accept a customer ID from the browser. Money is integer centavos. IDs below are database IDs, not template keys such as `p1`.

| Screen / action | Method and route | Body / result |
| --- | --- | --- |
| Account → Password & security | POST `/auth/password` | `{current_password,new_password,confirm_password}`. Requires current password; new password 10+ characters, at most 72 bytes. Rotates CSRF/session and invalidates older sessions through `users.auth_version`. Shared role login must pass the current auth version to `Session::login`. |
| Product Save / Account → Saved items | GET `/saved-products`, PUT or DELETE `/saved-products/{product_id}` | Persistent own list. Saving requires a visible public product. Previously saved unavailable listings remain listed and removable. |
| Account → My vouchers | GET `/vouchers`, POST `/vouchers/{voucher_id}/claim` | Read shared offers and own claim/use status. Claim is idempotent; checks activation, start, expiry and global usage limit. |
| Checkout voucher pickers | POST `/checkout/quote`, POST `/checkout` | Add `voucher_ids:[integer]` alongside existing items/address. Return quote/order includes merchandise/shipping discounts, net total and applied vouchers. Checkout requires its existing idempotency header and verified email. |
| Message shop / inbox | GET or POST `/conversations` | POST `{seller_id}` opens the customer's thread with an approved active public shop. GET lists own threads, preview and unread count. |
| Open conversation | GET `/conversations/{id}` | Latest 50 messages, `has_older`; optional `before=message_id` loads older messages. |
| Send / read messages | POST `/conversations/{id}/messages`, PATCH `/conversations/{id}/read` | Send `{body,client_key}`; text 1–2,000 characters, retry key 16–100 characters. Same key with another payload is rejected. Read marks incoming messages. UI supports older messages and refreshes an idle visible inbox every 15 seconds. |
| Private evidence / business documents | POST `/uploads` | `{purpose:"return" or "application",name,content:base64}`. JPEG/PNG/WebP, or PDF for applications; max 2 MiB each, max 30 uploads/hour/account. Images validated by MIME and dimensions, max 12,000 px each dimension. Files stay outside `public/`. |
| View own attachment | GET `public/api/attachment.php?id={upload_id}` | Binary, session-authorized owner-only response. Images inline; PDFs download with sandbox/nosniff headers. Another customer receives 404, guest 401. This is a separate endpoint, not a JSON `route`. |
| Purchases → Return / refund | GET or POST `/returns` | POST `{order_item_id,quantity,reason,explanation,solution,evidence_ids:[],client_key}`. Reasons: `Damaged item`, `Wrong item`, `Missing parts`, `Not as described`, `Other`; explanation 10–3,000 chars. Solutions `return_refund` / `refund_only`. Lists own cases, events and evidence metadata. |
| Rejected case → Appeal | POST `/returns/{id}/appeal` | `{explanation,evidence_ids:[]}`. Only own rejected case; transitions to `escalated` for management. No customer endpoint approves cases or issues money. |
| Account → Sell on SaleSync | GET or POST `/seller-applications` | Submit `{store_name,business_name,business_type,contact_name,contact_email,phone,address_text,description,document_ids:[]}`. Philippine mobile required. Own status/history returned. Pending application cannot be duplicated; `needs_information` may be updated/resubmitted; a rejected applicant may apply again. Permissions remain customer. |
| Account → Notifications | GET or PATCH `/preferences` | Boolean `{orders,messages,promotions}`. Defaults true/true/false. Applies to optional email; in-app notices remain available, mandatory account/security emails stay enabled. |

The upload JSON body alone permits up to 3,000,000 bytes; other routes retain the 64 KiB limit. Return/application submissions accept up to five new files. Uploaded IDs must belong to the customer, match the purpose and be unused in another submission. Existing submitted evidence is retained.

## Policies implemented for review

- Voucher order: one shop voucher per participating shop, then one platform voucher, then one shipping voucher. Fixed values, caps and minimum spend use centavos; percent values are whole percentages 1–100. Minimum spend is checked against the remaining eligible merchandise after earlier discounts. Discounts never exceed the eligible amount. Allocation to order lines preserves the exact discount sum.
- `starts_at` / `expires_at` use full UTC timestamps. Offers are inactive by default. Seller/management must create actual offers; this update does not invent active vouchers in the user's database.
- Claims are consumed atomically at order creation and remain consumed after cancellation. Changing that policy requires a coordinated business decision. Zero-payable baskets are rejected explicitly; the current hosted payment flow expects a positive payable amount.
- Return requests require a paid order and a recorded `delivered` or `completed` event in `order_status_history`. The existing order enum supports `completed`; history can also consume a delivered event supplied by fulfillment. `return_request_days` defaults to **7**, configurable pending team confirmation. No client-supplied delivery date is trusted.
- Claimed units cannot exceed purchased units across cases, including rejected cases that can be appealed. A final `refund_denied` decision releases those units for another request while the request window remains open. Eligible merchandise excludes item discounts; shipping is excluded. This is an eligibility ceiling, not a refund authorization or provider result.
- Return and application attachment IDs are permanent links once submitted. Unsubmitted uploads may remain after validation/network errors; maintenance may later remove old unreferenced uploads under an agreed retention policy.

## Connection points for teammates

| Owner | Shared records and responsibilities |
| --- | --- |
| Seller | Read `customer_threads` belonging to their `seller_profiles.seller_id`; authorize seller user on every read/write. Write `customer_messages` with the real seller `sender_id`, unique retry key and server timestamp; mark customer messages read. Emit a customer `Message` notification. Review own order-item `return_cases`, add `return_events`, and update permitted case states/reply. Seller module needs an explicitly authorized attachment reader for evidence linked to its own cases. |
| Management | Create platform/shipping `vouchers`, approve shared offer policy; review `seller_applications` and append `application_events`; provision seller permissions only after approval. Handle `escalated` returns, record decisions/events, and execute/verify authorized refunds through its payment workflow. Management needs authorized readers for submitted documents/evidence. |
| Maintenance | Operate private attachment storage, notification outbox, configured mail transport and scheduled jobs. No public attachment directory. Preserve existing migrations; add new numbered migrations and foreign-key checks. |
| Shared host | Use the same users, database and schema. Adopt `auth_version` in shared login/session checks. Emit notifications with `NotificationService::notify()` inside the business transaction; use context `order`, `thread`, `return` or `application` and the corresponding ID. |

`return_cases` states: requested, approved, rejected, returned, refund_pending, refunded, escalated, management_approved, refund_denied. `seller_applications` states: pending, needs_information, approved, rejected, suspended. These enums describe shared records; this customer branch implements only customer submissions/resubmissions/appeals. External decisions are fixtures in isolated automated tests, never fake live customer success.

## Email outbox operation

Run `php scripts/send-notifications.php` from the project root, manually or with a scheduled task. It processes up to 100 queued/failed records, limits attempts to three, takes an exclusive worker lock and rechecks current preferences/account status. `log` writes private development mail and records `logged`; `mail` records `sent` after PHP mail accepts the message, not proof of inbox delivery. Failed records retain their error. Delivery is at least once: a process failure after transport acceptance and before recording success can cause a duplicate; provider-level deduplication is a future transport integration concern.

Real email transport, PayMongo sandbox acceptance, independently progressing per-seller fulfillment/pickup, and authoritative merchant invoice rules remain integration/acceptance work. Finishing these seven customer features does not certify unseen seller/admin modules.

## Upgrade and verification

Run `php scripts/migrate.php`. Existing databases receive a private SQLite backup under `storage/backups/` before pending migrations. Table rebuilds disable foreign-key enforcement outside the transaction, check all foreign keys before commit and restore enforcement afterward; a failed migration rolls back. The upgrade test contains an existing order, item, payment and history.

```powershell
& E:\xampp\php\php.exe tests/run.php
& E:\xampp\php\php.exe tests/customer-features.php
node tests/http.mjs
# With a separate headless Edge/Chromium CDP endpoint on port 9223:
node tests/customer-ui.mjs
```

All tests use isolated databases. Browser tests cover saved products, preferences, message/reply/read state, application submission/status, voucher checkout, return image upload/case/appeal and password change/re-login, plus the existing shopping/address flow and mobile layouts. Provider payments and seller/admin decisions are explicit test fixtures only.
