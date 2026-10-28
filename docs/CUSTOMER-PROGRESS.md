# Customer branch progress — October 28, 2026

Latest local update: [all five reproduced defects are fixed](CUSTOMER-DEFECT-FIXES.md), including reviewed-quote validation, session clearing and stable message state. This supersedes the historical open-defect list below; external-service/cross-role gaps remain.

This branch contains the customer module. Each teammate continues their own role module separately; cross-role assembly remains a later milestone.

## Achieved

- Connected the PHP/SQLite backend to the coworker's customer design through one page, `public/index.php`.
- Grouped customer backend, JavaScript, templates and dedicated tests in their customer directories.
- Added registration contact details, birthday/age 18+ checks and a default Home address, with local Province/Region → City/Municipality → Barangay dropdowns also used by Add/Edit Address.
- Made registration compact with account/address steps, responsive paired fields, progress indication and Back preserving entered data.
- Enforced exactly 11 phone digits in customer forms and backend validation, including typing/paste handling.
- Connected product browsing/options, comparisons, cart, selected-item checkout, stock reservation, saved purchases and order snapshots.
- Added authenticated password changes, current-password validation, session revocation and independent show/hide controls in Account Settings.
- Added persistent favorite products, a top-header heart/count and access to Account → Favorites.
- Added voucher claims, server-validated discounts and transactional redemption.
- Added customer/store conversations, sending/read states and older-message loading.
- Added customer return requests, private evidence, case status/timeline and appeal submission, with the confirmed seven-day window.
- Added seller-application submission/status and notification preferences/outbox support.
- Kept View Invoice view-only without HTML download, and made checkout address options wrap/scroll within the dialog.
- Fixed the very narrow-screen search layout and removal from shared comparison links.
- Documented the API, schema/shared contracts, module boundaries and QA evidence.

## Validation and honest status

The earlier regression run passed 428 assertions: 78 foundation/service, 56 customer-feature, 141 HTTP/API and 153 browser checks. Syntax, database integrity and local private-file access checks also passed.

The subsequent adversarial audit originally reproduced **five defects**, since fixed in the linked update:

1. Checkout accepts a changed higher price without reviewed-quote reconfirmation.
2. Expired server sessions leave stale cached customer identity/content visible.
3. Unread counts omit messages outside the loaded page.
4. Conversation refresh discards loaded older messages.
5. Returning to a tab can erase an unsent message draft.

The audit commands gate those cases and now pass with the fixes. They use isolated fixture data. See [the readiness audit](CUSTOMER-READINESS-AUDIT.md) for original expected/actual evidence and reproduction commands.

Real email delivery, PayMongo sandbox acceptance, independent seller delivery/pickup tracking, refund-result totals, merchant invoice issuance, abandoned-order expiry and actual cross-role acceptance remain incomplete. Profile corrections and forgotten-password recovery are also limited. This branch is development work and does not certify readiness for real customer operation.
