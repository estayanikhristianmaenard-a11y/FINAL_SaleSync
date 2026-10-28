# Customer QA — October 28, 2026

**Readiness correction:** the later [adversarial readiness audit](CUSTOMER-READINESS-AUDIT.md) reproduced five defects despite these passing checks. All five have since been [fixed and verified](CUSTOMER-DEFECT-FIXES.md). This earlier regression pass is not a release/readiness verdict, and the external-service/cross-role gaps remain.

Scope: current local `customer` branch, including registration, strict 11-digit phone input and the latest invoice/password/address/Favorites changes. Seller/admin branches were not merged or modified. Automated business mutations use isolated databases; the existing local customer database received read-only integrity checks.

## Results

| Check | Result |
| --- | --- |
| `php tests/run.php` | 78 passed: customer foundation, module boundaries, stock, checkout and payment verification |
| `php tests/customer/customer-features.php` | 56 passed: feature services, ownership, phone rules, migration preservation, vouchers, messages, returns/appeals and applications |
| `node tests/http.mjs` | 141 passed: sessions, CSRF, routes, authorization, private attachments and notification worker |
| `node tests/customer/customer-ui.mjs` | 153 passed: actual customer forms, desktop/mobile, guest access, persistence and latest UI changes |
| PHP syntax | 39 source/test/config/entry-point files passed |
| JavaScript syntax | 13 customer/test/preparation files passed; changed files checked again after QA fixes |
| Diff whitespace | Passed; Git's Windows line-ending notices are informational |

**428 automated checks passed.** Browser validation reported no uncaught exceptions or missing scripts, styles or images. Final browser artifacts are in ignored `storage/customer-ui-tests-1793117442304/`, including `results.json` and screenshots.

## Fixes made during QA

1. At 320px, the four header shortcuts reduced the search input to about 27px. At widths up to 360px, search now occupies its own full row, with the logo and shortcuts above it. Regression checks cover 320px, 768px and 940px for overflow, usable search width and all four shortcuts. Existing 390px and desktop checks also pass.
2. Removing a product from a shared comparison URL previously left it displayed because the URL still contained its ID. Remove now updates the comparison selection and shared route. The regression test verifies the remaining columns and the differences-only filter.

## Customer flows exercised

- Registration's account/address steps, password mismatch, age 18+ validation, phone typing/paste limit, linked province/city/barangay selection, Back preserving data, canonical default-address save and email token verification.
- Sign-in/out, persisted profile, older-session revocation after password change, and independent show/hide controls on all three Account Settings password fields.
- Browsing/search/filter routes, product variants, comparison and its difference filter, cart quantities/selections, checkout totals, vouchers, order snapshots, unpaid cancellation and restored stock.
- View Invoice contains the saved order, is view-only and has no HTML download control.
- Long checkout addresses wrap inside the mobile dropdown; choosing updates its summary, Cancel preserves the destination, and confirmation saves the selected address into the order snapshot.
- Favorites header shortcut, persisted favorite products, count updates and guest sign-in requirement.
- Customer/store messages, seller-reply fixture, persisted incoming read state, application submission/status, private return evidence, case timeline and appeal submission.
- Notification read state, saved email preferences and the development delivery worker.

## Local installation checks

- Website: HTTP 200 at `http://localhost/FINAL_SaleSync/public/`.
- Apache: HTTP 403 for direct requests to `app/bootstrap.php`, `config/local.php`, `storage/salesync.sqlite` and `.git/config`.
- Existing SQLite database: `PRAGMA quick_check` returned `ok`; `PRAGMA foreign_key_check` found zero violations; five migrations recorded.
- Configured return window: seven days.

## Remaining acceptance boundaries

- PayMongo test credentials are absent. Payment authenticity/state handling passes against the explicit test gateway; a real hosted sandbox purchase/webhook still needs configuration and acceptance testing.
- Mail driver is `log`. Verification and notification records work locally; delivery to a real inbox remains unverified until a real transport is configured.
- Seller replies/reviews, management application/appeal decisions and refund execution are isolated fixtures in these tests. Actual cross-role integration requires the teammates' modules and their adopted shared contracts.
- Shared per-seller delivery/pickup tracking and authoritative merchant invoice issuance remain the documented integration work. This QA does not certify unseen modules or external services.

Changes remain local on `customer`, awaiting a new commit and push.
