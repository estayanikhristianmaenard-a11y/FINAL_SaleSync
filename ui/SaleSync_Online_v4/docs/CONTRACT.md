# Shared module contract

Current integration contract: [BACKEND_HANDOFF.md](BACKEND_HANDOFF.md).

## Runtime

Classic scripts are loaded in `build.py` order. `S` is the local state snapshot; `view` contains transient UI state. Guarded commands mutate records and call `commit(event, payload)`. The event bus records notifications and related updates. `save()` persists the snapshot under `salesync-marketplace-v3`; version 4 records and idempotent commerce migration are retained for compatibility.

The core exposes `SaleSync.auth`, `SaleSync.router`, `SaleSync.store`, `SaleSync.selectors` and `SaleSync.actions`. The local conventions are not a security boundary; the server must independently validate every operation.

## Important records

| Record | Fields / relationships |
| --- | --- |
| User | id, role, status, loginEnabled, profile, addresses, notificationPrefs, suspension |
| Store | id, ownerUserId, status, category, identity, application, pickup |
| Product | id, storeId, sku, price, variants, images, specs, categoryId, storeCategoryId, published, hidden, archivedDuplicate, canonicalId |
| Variant | stable id, label/name, attributes, add, imageIndex |
| Voucher / claim | scope shop/platform/shipping, code, value, cap, dates, minimum, usageLimit; per-user usedOrderId |
| Order | customer, item/option/price snapshots, addressSnapshot/label, shipments, shippingDiscount, merchandise discounts and allocations |
| Shipment | store snapshot, delivery/pickup method, status, pickup location/code, tracking, history |
| Return | purchased item/variant and quantity, solution, reason, evidence, seller reply, tracking, inspection, refund, timeline |
| Moderation case | product, policy, reason, deadline, seller explanation/evidence, decision and history |
| Audit / settings / report history | actor, time, record, reason, before/after or generated period/totals |

## Money and inventory

Quotes use integer centavos internally. `optionPrice` combines the base price with the configured option delta. `buildQuote` allocates shipping discounts exactly. `placeOrder` aggregates stock demand across options and snapshots all financial/display values.

The product ledger supports opening, allocation, adjustment, reservation, release, commit, restock and reconciliation records. The browser version shares stock across options. The backend must adopt explicit sellable SKU/variant inventory and transactional reservations.

`returnLimit` caps refund amounts using purchased option quantities and allocated discounts. `refund` rejects repeat authorization and creates payout adjustments. Production provider acknowledgment and settlement policies are separate backend responsibilities.

## Persistence and adapters

Maintenance can export JSON or restore supported v3/v4 backups with the required RESTORE acknowledgment. Jobs and webhook history are bounded. Housekeeping runs at boot, visibility changes and periodic browser intervals; it must become a server job.

`PayMongoMock`, `CourierMock`, `EmailMock` and `POSMock` are replaceable local adapters. No real payment, refund, delivery or email occurs. Keep production secrets and authorization outside the frontend.
