# SaleSync Management Admin API

Backend foundation for the SaleSync Management Admin. This service uses Node.js 20+, TypeScript, Express, and PostgreSQL. It contains the initial relational schema, health endpoints, database migration runner, and a fail-closed admin JWT guard. Business module handlers are added in subsequent implementation steps.

## Local setup

1. Install Node.js 20+ and Docker Compose.
2. Copy `.env.example` to `.env`; replace `ADMIN_JWT_SECRET` with a random secret of at least 32 characters. Keep `.env` out of version control.
3. Start PostgreSQL with `docker compose up -d postgres`.
4. Install packages with `npm install`.
5. Apply pending database migrations with `npm run db:migrate`.
6. Start the API with `npm run dev`.

The liveness endpoint is `GET /health/live`; readiness (including a database connection check) is `GET /health/ready`. Admin endpoints are mounted under `/api/admin` and require an HS256 JWT with the configured issuer, audience, a string subject, and `role: "admin"`. Integrate this guard with the deployment's trusted identity provider before production.

## Database changes

Put ordered SQL migrations under `src/migrations` using names such as `0002_add_notification_outbox.sql`. The migration runner applies each file once and records it in `schema_migrations`. A failed migration is rolled back. Review migrations before running against any shared or production database.

## Current implementation boundary

The first code increment establishes app startup, configuration validation, PostgreSQL pooling, initial schema application, basic health checks, and admin-route protection. Dashboard, user, seller, product, order, payment, report, settings, activity-log, and seed handlers remain to be implemented in their respective modules.
