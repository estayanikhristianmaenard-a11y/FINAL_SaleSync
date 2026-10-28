# SaleSync Online — backend handoff

The existing project and `SaleSync_Online_ALL.html` have been updated in place. This package includes four portals, one enabled testing account per role, source files, local assets, browser checks and backend integration notes.

## Start

Open **SaleSync_Online_ALL.html** for all four portals in one offline file, or open **index.html** for the separate Customer, Seller, Management and Maintenance pages. Use [TEST_ACCOUNTS.txt](TEST_ACCOUNTS.txt) for credentials. The testing password is `SaleSync2026!`.

For shared data between the independent portals, run `python -m http.server 8000` in this folder and open `http://127.0.0.1:8000/`. Sessions are separate per portal; data is shared per browser origin.

## Included changes

- Product galleries, photographed color/design/size options, editable option prices and photo selection. Artificial duplicate editions are archived; historical records remain intact.
- Clear category artwork and nested category filters, a manual hero carousel, consistent notification icons, a redesigned sign-in/registration page and useful like-for-like comparisons.
- Overall customer/seller inboxes with search, unread filters, read receipts and related orders.
- Named address selection, delivery or store pickup, separate shop/platform/shipping vouchers, detailed totals and printable itemized invoices.
- Return/refund cases with evidence, seller explanations, return tracking, inspection, appeals, refund limits, stock adjustments and timelines.
- Compact Seller and Management layouts, application review, reasoned account suspension, seller-response moderation, payment reconciliation, per-shop peso earnings, printable HTML sales reports, typed settings and inspectable audit decisions.
- Existing Maintenance tools for jobs, adapter events, incidents, integrity and controlled backup/restore.

There are **280 retained product records**, including **141 visible product records** and **139 archived duplicate/source repetitions**. Product/store IDs and historical transactions are preserved. Fifty-one manufacturer selections contribute 152 additional gallery views. Fixed-design products show their actual design rather than invented unpictured colors. Display brand spellings remain intentionally altered; source credits and lettering inside photos retain their original spelling.

## Build and verify

```sh
python build.py
node tests/browser-check.mjs verify
```

The browser runner requires a dedicated Chrome DevTools session on port 9222; see [tests/README.md](tests/README.md). Generated HTML includes embedded assets. Rebuilding unchanged source produces identical output. Results and current screenshots are in `docs/`.

## Backend integration

Start with [BACKEND_HANDOFF.md](docs/BACKEND_HANDOFF.md), [CONTRACT.md](docs/CONTRACT.md) and [SHOPEE_REFERENCES.md](docs/SHOPEE_REFERENCES.md). Shopee references inform public purchase, voucher and return patterns; SaleSync's administrative decisions and store-pickup adaptation are its own design.

This is an interactive frontend with browser-local persistence and local provider adapters. Real authentication, role enforcement, database transactions, payment/refund webhooks, courier bookings, email, evidence storage, invoice compliance and background schedulers belong on the backend. Product-image rights and catalog data require merchant review before publication.
