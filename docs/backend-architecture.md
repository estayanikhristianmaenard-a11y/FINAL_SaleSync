# SaleSync Management Admin — Backend Architecture (Step 1)

## Scope and assumptions

The repository initially had no application source or framework configuration. The implementation baseline is TypeScript on Node.js 20+, Express 5, and PostgreSQL 16. PostgreSQL is the relational database target. Use the migration runner for schema evolution; do not apply the schema file directly to an existing production database without review.

Money is stored as integer minor units in PHP centavos (`amount_centavos`) to avoid floating-point rounding. Timestamps use `timestamptz` and are stored in UTC. API responses should serialize timestamps as ISO-8601 UTC and amounts as decimal strings or integer centavos consistently. Operational records are soft-disabled only where restoration is a requirement; audit history is append-only.

## Suggested project structure

```text
src/
  app/                 # server bootstrap, dependency wiring, API version routers
  modules/
    dashboard/         # aggregate metrics, activity feed
    users/             # customer list/detail and account status use cases
    seller-applications/ # application/store queries and decisions
    products/          # moderation queries/actions
    orders/            # read-only oversight and detail
    payments/          # PayMongo adapter, webhook verification, payment reads
    sales-reports/     # report query and CSV export
    system-settings/   # admin product CRUD, categories, business settings
    activity-log/      # read-only audit queries
  shared/
    auth/              # authentication, admin authorization, actor context
    db/                # connection, transaction helper, repositories
    audit/             # transactional audit writer
    http/              # validation, errors, pagination, response conventions
    notifications/     # notification provider interface and dispatch jobs
    security/          # secret redaction, rate limiting, webhook verification
  jobs/                # notification delivery and other asynchronous work
  migrations/          # ordered, versioned schema migrations
  seeds/               # deterministic development/demo data generators
tests/
  unit/
  integration/
  fixtures/
```

Each module should expose route/controller, request validation, application service/use case, and repository/query code. Keep authorization and transaction boundaries in application services; controllers should translate HTTP input/output only. All state-changing admin actions should write the business change and its audit entry in the same database transaction. Notification delivery should be queued after commit (outbox pattern recommended) so a provider outage cannot roll back an approved/rejected decision.

## Module and route ownership

Routes are grouped in the requested navigation order. The navigation is a client concern; the API groups below provide stable resource boundaries.

| Area | Suggested API surface | Access and invariants |
|---|---|---|
| Dashboard | `GET /api/admin/dashboard/metrics`, `GET /api/admin/dashboard/activity` | Admin read; aggregate counts and sales from persisted business records. |
| Users | `GET /api/admin/users`, `GET /api/admin/users/{id}`, `POST /api/admin/users/{id}/activate`, `/suspend`, `/restore` | Admin read/write; status transitions audited transactionally. Search/status filters are validated and paginated. |
| Seller Applications | `GET /api/admin/seller-applications`, `GET /api/admin/seller-applications/{id}`, `GET /api/admin/stores/{id}`, decision and store-status actions | Rejection requires a non-empty reason; decisions record actor/time and enqueue applicant notification after commit. |
| Products | `GET /api/admin/products`, `GET /api/admin/products/{id}`, moderation deactivate/restore actions | Admin moderation is audited; seller/category/status filters are indexed. Product creation/edit/publish/archive belongs to System Settings. |
| Orders | `GET /api/admin/orders`, `GET /api/admin/orders/{id}` | Read-only oversight. Do not expose fulfillment mutation routes under admin. Sellers own fulfillment transitions. |
| Sales Reports | `GET /api/admin/reports/sales`, `GET /api/admin/reports/sales.csv` | Same query/service and date-filter semantics for JSON and CSV. CSV columns: `Order No.`, `Date`, `Customer`, `Seller`, `Total`, `Payment Status`, `Order Status`; include a clearly labeled summary totals row. Audit exports. |
| System Settings | Product create/edit/publish/archive, categories, business settings | Admin writes; category deactivation should be soft and blocked or handled explicitly when referenced by active products. Never return provider credentials. |
| Payments | `GET /api/admin/payments`, `GET /api/admin/payments/{id}`; provider webhooks under a non-admin integration route | Read-only admin view; validate signed webhooks and idempotently record provider events. Never send PayMongo secret keys to clients. |
| Activity Log | `GET /api/admin/activity` | Read-only; filter by time/action/actor; restrict access to authorized admins. |

## Data model

The executable PostgreSQL baseline is [0001_initial_schema.sql](../src/migrations/0001_initial_schema.sql). Key relationships:

- `users` contains customer, seller, and admin identities; `seller_profiles` extends seller users; `stores` are owned by seller profiles.
- `seller_applications` belong to seller users and capture review state, reviewer, decision time, and rejection reason. Application status is separate from store status.
- `categories` classify `products`; each product belongs to a seller and may be created by an administrator. Product status supports draft, published, archived, and deactivated moderation state.
- `orders` belong to a customer. `order_items` snapshot seller, SKU, product name, unit price, and quantity at purchase time, so historical reports remain accurate after catalog edits. An order can contain items from multiple sellers; seller sales must aggregate order items by seller.
- `payments` belong to orders and retain provider references, amount, status, and safe metadata only. `payment_events` record webhook/event identifiers for idempotency.
- `order_status_history` records seller fulfillment transitions; admins may read it but do not mutate it.
- `admin_audit_logs` record actor, action, target, timestamp, request correlation ID, and redacted before/after JSON. Keep append-only and do not store secrets or full payment payloads.
- `business_settings` holds typed settings values and update provenance. Validate keys and value shapes in application code.

### Constraints and query/index strategy

- Unique normalized user email, seller application per user (enforced by the schema baseline), unique SKU, unique order number, unique provider transaction reference when present, and unique provider event ID.
- Check constraints enforce positive prices/quantities, non-negative stock and payment amounts, and valid enumerated state values. Application services enforce transition rules that are too contextual for SQL checks.
- Index common filters: user role/status; application status and submission time; store status; product status/category/seller; order creation time/status/customer; order-item seller/order; payment status/creation time/order; audit timestamp/action/actor; order status history by order/time.
- Use keyset pagination for large activity/order/product lists. Apply bounded date ranges and pagination limits. Avoid indexing every JSON field; add a targeted expression or generated-column index only when an observed query requires it.
- Reports should use order item snapshots and payment/order status according to an explicit business rule. Define gross sales as the sum of eligible paid order totals and net sales as gross sales less explicitly modeled refunds/fees; this baseline includes refunds as a future extension point and does not silently infer provider fees.

## Security and consistency rules

1. Require authenticated admin identity and role authorization on `/api/admin/*`; do not trust actor IDs from request bodies.
2. In one DB transaction, perform every status/decision/moderation/settings change and insert its audit row. Reject invalid state transitions and make repeated requests idempotent where practical.
3. Keep PayMongo secret key in server environment/secret manager. Return only safe payment fields. Verify webhook signatures using the raw request body and make event processing idempotent.
4. Escape CSV cells beginning with `=`, `+`, `-`, or `@` to prevent spreadsheet formula execution. Stream large exports, use the exact report query service, and audit the export with filters and actor (not sensitive data).
5. Do not cascade-delete orders, payments, audit rows, or status history. Use foreign keys and explicit retention policies.
6. Write notification jobs to `notification_outbox` in the same transaction as decisions and deliver them asynchronously after commit.

## Decisions to confirm before implementation

- Existing identity/authentication provider. The baseline admin guard currently validates signed HS256 JWTs using configured issuer/audience and an environment secret; production should use the existing identity provider and managed secret storage.
- Whether one order may contain items from multiple sellers (the schema supports this).
- Exact gross/net sales rules, including refunds, discounts, shipping, and platform fees.
- Whether an account can simultaneously have multiple roles; the baseline uses one primary role per user.
- Whether the existing marketplace already has users/products/orders tables that this backend must share rather than recreate.
