# Customer module audit — October 27, 2026

Latest local update: [the five adversarial defects are fixed](CUSTOMER-DEFECT-FIXES.md). The [readiness audit](CUSTOMER-READINESS-AUDIT.md) retains the original reproductions and incomplete business integrations. Passing these checks does not establish external-service or cross-role readiness.

Updated after implementing the seven gaps identified in the earlier audit. Scope follows the user's [final role plan](FINAL-ROLE-PLAN.md), registration/18+ policy and Philippine address requirements. Work remains on `customer`; other modules have not been merged or certified.

## Requested gaps now implemented

| Customer requirement | Current behavior |
| --- | --- |
| Password management | Current-password check, new password validation/hash, CSRF/session rotation, older-session invalidation and security notice |
| Saved products | Persistent own save/remove/list; unavailable saved listings remain removable |
| Vouchers | Shared offers, eligible claims, shop/platform/shipping selection, server discount allocation and atomic redemption |
| Messages | Own customer/store conversations, sending, older messages, incoming read state and outgoing read receipts |
| Returns/refunds | Paid delivery/completion eligibility, quantity/net-value ceilings, private evidence, own case timeline and appeal |
| Seller applications | Own submission/status/history, duplicate-pending prevention and requested-information resubmission |
| Notification preferences | Persistent optional email choices applied to queuing/delivery; essential inbox/security records retained |

All seven use the existing single-page customer UI and PHP API. [The feature handoff](CUSTOMER-FEATURES.md) documents inputs, policies and teammate responsibilities. Tests simulate external decisions only through isolated fixtures.

## Remaining integration and acceptance work

| Area | Existing behavior | Remaining boundary |
| --- | --- | --- |
| Profile | Name editing; registered phone/birthday displayed; registration enforces age 18 | Team defines correction permissions for phone/birthday; extra demographics are not automatically required |
| Tracking | Saved order status history and tracking dialog | Shared delivery/pickup data and independently progressing per-seller fulfillment; current status is order-wide |
| Payment status | PayMongo test adapter, signed webhook validation and reconciliation | Actual credentials, reachable webhook and real sandbox purchase remain unconfigured/unverified |
| Email | Token verification/resend and notification worker | Default log driver writes private files; real transport/inbox acceptance still needed |
| Invoices | Purchase document includes merchandise/shipping discounts and net total | Merchant-authoritative issuance/numbering/statutory fields need an agreed service |
| Cross-role workflows | Shared voucher/message/return/application/event records | Seller/management implement authorized replies, reviews, decisions, refund execution and participant attachment readers, then combined acceptance tests |
| Business policies | Configurable seven-day return window, one voucher per scope/shop and consumption at order creation | Documented defaults need team acceptance where the matrix did not specify policy |

Customer routes cannot create offers, approve applications, manufacture delivery/payment events or authorize refunds. Password recovery, following stores and product reviews are outside this seven-feature request; detailed policies were not specified by the role matrix.

## Validation

The isolated suites cover existing shopping/payment behavior and the new password, saved-product, preference, voucher, conversation, upload, return/appeal and application flows. A populated-database upgrade test preserves an existing order, item, payment and history and checks foreign keys. HTTP tests exercise routing, CSRF, session revocation, private binary attachment ownership and notification delivery logging. Browser tests submit the real forms, check server persistence and check missing assets, exceptions and mobile overflow.

Run the commands in [the feature handoff](CUSTOMER-FEATURES.md). Passing them establishes tested customer behavior; it does not certify unseen modules or real provider delivery. No weighted completion percentage is assigned by the plan.
