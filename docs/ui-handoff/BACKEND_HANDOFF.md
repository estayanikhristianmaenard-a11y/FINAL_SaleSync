# SaleSync backend handoff

Release date: 26 October 2026. The existing project files and standalone HTML were updated in place. This package is a working browser-local frontend and integration reference. It contains no production server, database, payment account, courier booking service or real email transport.

## Open and build

- `SaleSync_Online_ALL.html`: all four portals with embedded scripts, styles, photographs and icons. Opens without a server.
- `index.html`: launcher for `customer.html`, `seller.html`, `management.html` and `maintenance.html`.
- Run `python -m http.server 8000` from the project directory when testing independent portals together. Open `http://127.0.0.1:8000/`. All portals must use the same origin to share local data.
- Run `python build.py` after changing source. The generated HTML files are deterministic. Do not edit generated bundles directly.
- `TEST_ACCOUNTS.txt` contains one enabled testing account for each role. Other retained contacts preserve fixture relationships and cannot sign in through the login form.

## Source map

| Files | Responsibility |
| --- | --- |
| `core/portal.js`, `auth.js`, `router.js`, `bootstrap.js` | Role sessions, authentication UI routing, registration, boot and event routing |
| `core/catalog.js`, `brands.js`, `product-photos.js`, `product-options.js`, `catalog-refresh.js` | Catalog defaults, intentionally altered display spellings, product-photo provenance and manufacturer options |
| `core/storefront-model.js`, `seed.js`, `store.js` | Categories, store identity, seed data, migration and browser persistence |
| `core/commerce-model.js` | Commerce migration, centavo calculations, address choice, quotes, voucher use, order snapshots and refund limits |
| `core/experience.js` | Customer login, inbox, checkout, account, notifications, product details, comparisons, invoices and return-case details |
| `core/experience-admin.js` | Operational queues, application review, per-shop financial summaries, report documents and audit inspection |
| `core/experience-actions.js` | Guarded commands for the expanded flows, evidence processing, pickup, moderation responses and document output |
| `core/actions.js`, `workflows.js`, `ledger.js`, `selectors.js`, `events.js` | Shared legacy-compatible commands, reservations, fulfillment, finance, background processing and notifications |
| `core/workspaces.js`, `workspace-features.js` | Common operational shell, catalog editing, galleries, collection management and order details |
| `core/experience.css` | Final common UI rules and responsive layouts |
| `customer/`, `seller/`, `management/`, `maintenance/` | Portal sections and styles |
| `assets/*sources.json`, `docs/IMAGE_CREDITS.md` | Image/model provenance, transformations and rights notes |
| `tests/` | Browser regression suites and responsive/standalone checks |

The application uses classic scripts and shared selectors. Keep script ordering in `build.py`. This is deliberately dependency-free; the backend team can move components into its chosen framework without changing the documented user journeys.

## Catalog and options

There are 280 retained product records. Of those, 139 duplicate or repeated-source records are archived from storefront results, leaving 141 visible product records. Historical IDs, order lines and inventory openings remain intact. Artificial Everyday/Select/Studio repetitions are no longer advertised as different photographed products.

Each visible product has its own product/gallery references and at least one valid design option. Apparel and shoes have size choices; imported catalogs include photographed colors, sizes and designs when available. Fixed-design items retain their actual design instead of inventing colors unsupported by the photographs. Fifty-one source selections supply 152 additional manufacturer views. Sellers can edit option labels, color/finish, size/design, prices and associated gallery views in Products → Options.

`variant.id` is stable for cart/order references. `variant.attributes` contains display attributes, `variant.add` is the configured price difference from the product base price, and `variant.imageIndex` selects the corresponding gallery image. The local model shares product inventory between its options. **Backend implementation must define stock per sellable SKU/variant and migrate stock ownership explicitly.** Never infer available stock from photographs or source-site availability.

Photo source metadata is not a commercial image license. Display spelling changes do not alter image ownership. Confirm merchant authorization, model data, prices and image rights before publishing a real catalog. Source credits preserve original names; lettering in photographs is unchanged.

## Checkout contract

1. Customer selects product options and quantities; Buy Now isolates its selected item from the cart.
2. Customer chooses a named saved address. This checkout choice does not silently change the saved default.
3. Each shop offers delivery or store pickup if both platform and shop allow pickup. The chosen location and hours are visible before order submission.
4. One shop voucher per shop, one platform voucher and one shipping voucher can be selected. The picker accepts a code or an eligible listed offer and explains minimum spend, expiry, usage and pickup exclusions.
5. The quote shows merchandise, original shipping, each discount and total separately. Pickup contributes zero shipping. A shipping voucher cannot create credit or discount a pickup-only basket.
6. Place Order validates the selected items/options, aggregated stock across options, address, pickup availability and voucher eligibility. It reserves stock and consumes voucher claims once.
7. Payment remains Pending until a provider event confirms it. Failure releases reservations; retry validates stock again. Provider event IDs are deduplicated.

Money is calculated in integer centavos and displayed in PHP. Shop/platform discounts are separate from shipping discounts. Shipping discount allocations sum exactly to the order shipping discount, including mixed delivery/pickup orders. Orders snapshot unit price, SKU, option name/attributes, recipient, address label, shop name, method, pickup location, shipping, discounts and commission rate.

The frontend has removed the checkout message-to-seller field. Customer/store communication belongs in Messages with related order references.

### Server requirements

The browser cannot be the authority for price, roles, vouchers or inventory. The server must recompute the quote, check SKU eligibility and reserve inventory/claim vouchers in one transaction. Require an idempotency key for order creation and refunds. Retry or stale quotes must not create duplicate orders or spend one voucher twice. Define voucher reinstatement after cancellation as an explicit policy; the local version retains consumed claims rather than silently issuing new ones.

## Fulfillment and pickup

| Method | States / actions | Actor |
| --- | --- | --- |
| Both | Awaiting Payment → To Process | Confirmed payment event |
| Both | To Process → Processing | Owning seller |
| Delivery | Processing → Ready for Shipment → Shipped | Seller packing / courier handover |
| Delivery | Shipped → Out for Delivery → Delivered; exception states also supported | Courier adapter |
| Pickup | Processing → Ready for Pickup | Owning seller |
| Pickup | Ready for Pickup → Delivered after matching collection code | Seller confirms physical collection |
| Both | Delivered → Completed | Customer receipt or scheduled completion |

The customer finds the pickup code in Track Order. Delivery status and collection code generation/verification need server endpoints, expiry/rate limits and auditable evidence. A client-visible code in this local file is not a secure pickup credential. Open return cases hold automatic completion and payout eligibility.

## Returns and refunds

My Purchases exposes Return/Refund only for delivered items within the configured policy window. The form selects the purchased item/option and quantity, reason, explanation, preferred solution and evidence images. Quantity and paid-value limits are enforced across cases.

| Transition | Actor / required record |
| --- | --- |
| Requested | Customer; purchased line, quantity, reason, description, solution and evidence |
| Requested → Approved or Rejected | Owning seller; explanation and optional evidence |
| Approved return → Returned | Customer dispatch; pickup/drop-off method, carrier and tracking/receipt reference |
| Returned → Refund Pending | Seller confirms receipt/inspection and resellable condition |
| Approved refund-only → Refund Pending | Seller accepts refund without requiring physical goods |
| Rejected → Escalated | Customer appeal, explanation and optional additional evidence |
| Escalated → Management Approved or Refund Denied | Management records evidence review and reason |
| Refund Pending → Refunded | Management authorization; provider integration must subsequently confirm completion |

The current internal `Returned` state means buyer dispatch has been recorded; seller inspection is still required. Preserve separate backend states for authorized return, collection scheduled, return in transit, received, inspected, refund submitted and provider-confirmed refund.

Refund limits use the eligible item quantity and allocated shop/platform discounts, capped by the unrefunded amount paid. A repeated refund is rejected. Refund-only cases cannot restock goods. Physical restocking occurs once, after seller inspection. Paid payout refunds create adjustment records. Provider settlement, fee reversals, platform subsidy reversal and shipping-fee refunds require explicit backend policies; displayed local seller-net values are an estimate under the current fixture rules.

Evidence is locally resized to bounded WebP images. Replace embedded evidence with private object storage, malware/type checks, access-controlled URLs, retention rules and upload references. The local model supports images, not a production video/PDF evidence service. Notification timelines must not expose another customer’s attachments or addresses.

## Messaging and account

- Customer and Seller have an overall inbox with conversation search, unread filtering, read receipts, latest-message previews and related purchases.
- A participant can read/send only their own customer/store threads in the local guards. The backend must authorize every fetch, send, attachment and read-receipt update independently.
- Add realtime delivery or polling, server timestamps, pagination, rate limiting, attachment controls and notification delivery.
- My Account separates profile, addresses, security, notification preferences, saved items, vouchers and seller application. Purchases, messages and notifications remain navigable modules.
- Notifications have readable icons, type/unread filters and context links. In-app order/security records remain available even if optional delivery preferences are disabled.

## Management decisions

- **Applications:** View complete business/contact details, supporting documents and review history before selecting Approved, Needs Information, Rejected or Suspended. The reviewer must confirm review and provide a reason.
- **Suspension:** Select reason category, duration and details. Preserve before/after state, actor, timestamp, expiry and customer notification. Scheduled expiry restores a timed suspension; indefinite cases require review. Guard privileged/self-account changes server-side.
- **Product moderation:** Create an explanation request without immediately hiding the listing. Seller responds with reasoning/evidence. Final restriction/restoration requires a seller response or the configured response deadline, plus a documented Management decision.
- **Order Oversight:** Search/filter cross-shop orders, inspect their fulfillment and recipient snapshots. It does not replace the seller fulfillment queue.
- **Payment Status:** Reconcile order amount, payment amount, attempts and provider/refund events. Do not expose manual production Pay controls.
- **Sales Reports:** Filter period and shop; view per-shop gross sales, shop discounts, refunds, commission and seller-net estimates. Generate a report document with summary, observations and transaction appendix; print/save PDF or download HTML. Reporting is separate from payment settlement.
- **Settings:** Typed, bounded rules for delivery fees, commission, returns, response/dispatch windows, reservation expiry and pickup. Every change records a reason and before/after values. Category, banner, registry and promotion management remain under System Settings.
- **Activity Log:** Search action/reference/reason, filter actor and date, and inspect detailed before/after decisions. Replace browser-editable history with append-only server audit records and an explicit retention/access policy.

## Suggested API surface

| Area | Operations |
| --- | --- |
| Authentication | Register, sign in/out, refresh session, reset password, verify email, authorize role/shop membership |
| Account | Read/update profile; address CRUD/default; preferences; wishlist/followed stores |
| Catalog | Search/filter products and nested categories; product/gallery/variant detail; store collections |
| Checkout | Server quote; available vouchers; claim/apply/release voucher; idempotent order creation |
| Payment | Create provider session; signed webhook; status; retry; idempotent refund submission/result |
| Fulfillment | Seller packing/readiness; courier booking/events; pickup location/slot/code/collection confirmation |
| Returns | Create case; private evidence; seller response; appeal; return tracking; inspection; refund authorization |
| Messaging | List/search threads; paginated messages; send; read receipt; participant events/notifications |
| Administration | Application detail/review; account decisions; moderation case/response/decision; settings history |
| Reporting | Server period/shop aggregation; report jobs; printable/PDF output; reconciliation/settlement views |
| Maintenance | Health, integration status, background jobs, controlled backup/restore, incidents and audit access |

## Integration acceptance criteria

1. No browser-supplied price, role, status, discount or inventory count is trusted.
2. Merchant/customer authorization is enforced on every record and file access.
3. Order/quote arithmetic reconciles in centavos; webhook/refund/order requests are idempotent.
4. Return/refund quantity cannot exceed the purchased option quantity or unrefunded paid value.
5. Inventory release/commit/restock runs once per transition under transaction locks.
6. Server schedulers handle reservation expiry, response deadlines, completion holds, payouts and timed suspensions while browsers are closed.
7. Real credentials and secrets are stored server-side. Replace the static browser hashing, editable local sessions and localStorage persistence.
8. Tax-compliant merchant invoice identifiers/fields, delivery commitments and consumer policies are configured and reviewed for the deployment jurisdiction.
9. Run the included frontend suite against the integrated adapters and add concurrent/server permission tests.

See [reference patterns](SHOPEE_REFERENCES.md), [module contract](CONTRACT.md), [testing guide](TEST_SCRIPTS.md) and [browser results](browser-regressions.json).
