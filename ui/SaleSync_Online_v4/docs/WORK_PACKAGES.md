# Backend integration work areas

Use BACKEND_HANDOFF.md and CONTRACT.md as the current integration contract. The original portal folders remain; revised commerce flows share these modules:

| Area | Files | Backend responsibilities |
| --- | --- | --- |
| Commerce | core/commerce-model.js, core/store.js, core/ledger.js, core/workflows.js | Authoritative quotes, reservations, orders, payment events, refunds and settlements |
| Customer experience | core/experience.js, customer/ | Catalog, account, addresses, inbox, checkout, comparison, invoice and returns |
| Administrative experience | core/experience-admin.js, core/experience-actions.js, core/workspaces.js | Applications, moderation, suspensions, seller operations, reports, settings and audit |
| Maintenance | maintenance/, core/adapters.js, core/events.js | Provider events, jobs, observability, backup and health |
| Catalog | core/product-photos.js, core/product-options.js, core/catalog-refresh.js, assets/ | Merchant validation, image rights, SKU options and storage |
| Delivery | build.py, tests/, docs/ | Rebuild portal files, regressions and API contracts |

Enforce authentication and authorization on the server for every operation. Shared classic scripts load in the order defined in build.py; preserve that order or explicitly convert dependencies to modules. Run regressions after changing shared contracts.
