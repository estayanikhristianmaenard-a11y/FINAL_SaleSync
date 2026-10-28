# SaleSync Management Admin API

SaleSync Management Admin API using Node.js 20+, TypeScript, Express, and PostgreSQL. It provides admin endpoints for dashboard, users, seller applications, product moderation, order oversight, PayMongo payment events, sales reports/CSV, System Settings, and the audit log.

## Local setup

1. Install Node.js 20+ and Docker Compose.
2. Copy `.env.example` to `.env`; replace `ADMIN_JWT_SECRET` with a random secret of at least 32 characters. Keep `.env` out of version control.
3. Start PostgreSQL with `docker compose up -d postgres`.
4. Install packages with `npm install`.
5. Apply pending database migrations with `npm run db:migrate`.
6. Start the API with `npm run dev`.

## SaleSync UI

The supplied browser UI is in `ui/SaleSync_Online_v4`. Serve that folder as static files (for example, `python -m http.server 8000` from that directory) and open `http://127.0.0.1:8000/`. The frontend is a separate local-first application; the Management API binding is included at `ui/SaleSync_Online_v4/management/admin-api.js`. The UI still needs Management selectors/actions converted to call that API before its data is backed by this server.

For local demo data, run `npm run db:seed` after migrations. It creates exactly 200 deterministic orders and payments spanning the preceding year with related customer, seller, store, and product records. Never run the demo seed against a shared or production database.

The liveness endpoint is `GET /health/live`; readiness (including a database connection check) is `GET /health/ready`. Admin endpoints are mounted under `/api/admin` and require an HS256 JWT with the configured issuer, audience, a string subject, and `role: "admin"`. Integrate this guard with the deployment's trusted identity provider before production.

## Database changes

Put ordered SQL migrations under `src/migrations` using names such as `0002_add_notification_outbox.sql`. The migration runner applies each file once and records it in `schema_migrations`. A failed migration is rolled back. Review migrations before running against any shared or production database.

## Current implementation boundary

Admin actions and audit entries commit in a single transaction. PayMongo webhook signature validation uses the raw request body and a server-side `PAYMONGO_WEBHOOK_SECRET`; no provider secret is returned by admin APIs. The supplied UI archive is a separate local-first bundle. See `docs/backend-architecture.md` for Management-only script binding notes.
