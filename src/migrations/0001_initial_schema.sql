-- SaleSync Management Admin baseline schema (PostgreSQL 15+).
-- Apply through the project's migration runner after review.

CREATE TABLE users (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email             text NOT NULL,
  password_hash     text,
  full_name         text NOT NULL,
  phone             text,
  role              text NOT NULL CHECK (role IN ('customer', 'seller', 'admin')),
  status            text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'pending')),
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now(),
  deleted_at        timestamptz
);
CREATE UNIQUE INDEX users_email_lower_uq ON users (lower(email));
CREATE INDEX users_role_status_created_idx ON users (role, status, created_at DESC) WHERE deleted_at IS NULL;

CREATE TABLE seller_profiles (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id           uuid NOT NULL UNIQUE REFERENCES users(id) ON DELETE RESTRICT,
  legal_name        text NOT NULL,
  tax_identifier    text,
  created_at        timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE seller_applications (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  seller_user_id    uuid NOT NULL UNIQUE REFERENCES users(id) ON DELETE RESTRICT,
  status            text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
  submitted_at      timestamptz NOT NULL DEFAULT now(),
  reviewed_by       uuid REFERENCES users(id) ON DELETE RESTRICT,
  reviewed_at       timestamptz,
  rejection_reason  text,
  application_data jsonb NOT NULL DEFAULT '{}'::jsonb,
  CHECK ((status <> 'rejected') OR (rejection_reason IS NOT NULL AND length(trim(rejection_reason)) > 0)),
  CHECK ((status = 'pending') OR (reviewed_at IS NOT NULL))
);
CREATE INDEX seller_applications_status_submitted_idx ON seller_applications (status, submitted_at DESC);

CREATE TABLE stores (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  seller_profile_id uuid NOT NULL UNIQUE REFERENCES seller_profiles(id) ON DELETE RESTRICT,
  name              text NOT NULL,
  status            text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'pending')),
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX stores_status_idx ON stores (status);

CREATE TABLE categories (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name              text NOT NULL,
  slug              text NOT NULL UNIQUE,
  status            text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
  created_by        uuid REFERENCES users(id) ON DELETE RESTRICT,
  updated_by        uuid REFERENCES users(id) ON DELETE RESTRICT,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX categories_status_name_idx ON categories (status, name);

CREATE TABLE products (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  seller_profile_id uuid NOT NULL REFERENCES seller_profiles(id) ON DELETE RESTRICT,
  category_id       uuid NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
  created_by        uuid REFERENCES users(id) ON DELETE RESTRICT,
  name              text NOT NULL,
  sku               text NOT NULL UNIQUE,
  description       text NOT NULL DEFAULT '',
  price_centavos    bigint NOT NULL CHECK (price_centavos >= 0),
  stock             integer NOT NULL DEFAULT 0 CHECK (stock >= 0),
  status            text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived', 'deactivated')),
  moderation_reason text,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now(),
  deleted_at        timestamptz
);
CREATE INDEX products_status_category_idx ON products (status, category_id) WHERE deleted_at IS NULL;
CREATE INDEX products_seller_status_idx ON products (seller_profile_id, status) WHERE deleted_at IS NULL;
CREATE INDEX products_created_at_idx ON products (created_at DESC) WHERE deleted_at IS NULL;

CREATE TABLE customer_addresses (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id       uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  recipient_name    text NOT NULL,
  line1             text NOT NULL,
  line2             text,
  city              text NOT NULL,
  region            text,
  postal_code       text,
  country_code      char(2) NOT NULL DEFAULT 'PH',
  created_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX customer_addresses_customer_idx ON customer_addresses (customer_id);

CREATE TABLE orders (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_no          text NOT NULL UNIQUE,
  customer_id       uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  shipping_address  jsonb NOT NULL,
  status            text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded')),
  payment_status    text NOT NULL DEFAULT 'pending' CHECK (payment_status IN ('pending', 'paid', 'failed', 'refunded')),
  subtotal_centavos bigint NOT NULL CHECK (subtotal_centavos >= 0),
  shipping_centavos bigint NOT NULL DEFAULT 0 CHECK (shipping_centavos >= 0),
  discount_centavos bigint NOT NULL DEFAULT 0 CHECK (discount_centavos >= 0),
  total_centavos    bigint NOT NULL CHECK (total_centavos >= 0),
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX orders_created_status_idx ON orders (created_at DESC, status);
CREATE INDEX orders_payment_created_idx ON orders (payment_status, created_at DESC);
CREATE INDEX orders_customer_created_idx ON orders (customer_id, created_at DESC);

CREATE TABLE order_items (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id          uuid NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
  product_id        uuid REFERENCES products(id) ON DELETE SET NULL,
  seller_profile_id uuid NOT NULL REFERENCES seller_profiles(id) ON DELETE RESTRICT,
  product_name      text NOT NULL,
  sku               text NOT NULL,
  unit_price_centavos bigint NOT NULL CHECK (unit_price_centavos >= 0),
  quantity          integer NOT NULL CHECK (quantity > 0),
  line_total_centavos bigint NOT NULL CHECK (line_total_centavos >= 0)
);
CREATE INDEX order_items_order_idx ON order_items (order_id);
CREATE INDEX order_items_seller_order_idx ON order_items (seller_profile_id, order_id);

CREATE TABLE order_status_history (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id          uuid NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
  from_status       text,
  to_status         text NOT NULL,
  changed_by        uuid REFERENCES users(id) ON DELETE RESTRICT,
  actor_role        text NOT NULL CHECK (actor_role IN ('seller', 'system')),
  note              text,
  changed_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_history_order_time_idx ON order_status_history (order_id, changed_at DESC);

CREATE TABLE payments (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id          uuid NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
  provider          text NOT NULL DEFAULT 'paymongo',
  provider_reference text,
  amount_centavos   bigint NOT NULL CHECK (amount_centavos >= 0),
  currency          char(3) NOT NULL DEFAULT 'PHP',
  status            text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'failed', 'refunded')),
  paid_at           timestamptz,
  safe_metadata     jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX payments_provider_reference_uq ON payments (provider, provider_reference) WHERE provider_reference IS NOT NULL;
CREATE INDEX payments_status_created_idx ON payments (status, created_at DESC);
CREATE INDEX payments_order_created_idx ON payments (order_id, created_at DESC);

CREATE TABLE payment_events (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  payment_id        uuid NOT NULL REFERENCES payments(id) ON DELETE RESTRICT,
  provider_event_id text NOT NULL UNIQUE,
  event_type        text NOT NULL,
  received_at       timestamptz NOT NULL DEFAULT now(),
  processed_at      timestamptz,
  processing_status text NOT NULL DEFAULT 'received' CHECK (processing_status IN ('received', 'processed', 'failed'))
);
CREATE INDEX payment_events_payment_time_idx ON payment_events (payment_id, received_at DESC);

CREATE TABLE business_settings (
  key               text PRIMARY KEY,
  value             jsonb NOT NULL,
  updated_by        uuid REFERENCES users(id) ON DELETE RESTRICT,
  updated_at        timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE admin_audit_logs (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_id          uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  action            text NOT NULL,
  target_type       text NOT NULL,
  target_id         uuid,
  before_data       jsonb,
  after_data        jsonb,
  metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
  request_id        text,
  occurred_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_occurred_idx ON admin_audit_logs (occurred_at DESC);
CREATE INDEX audit_action_occurred_idx ON admin_audit_logs (action, occurred_at DESC);
CREATE INDEX audit_actor_occurred_idx ON admin_audit_logs (actor_id, occurred_at DESC);
CREATE INDEX audit_target_idx ON admin_audit_logs (target_type, target_id, occurred_at DESC);

CREATE TABLE notification_outbox (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  deduplication_key text NOT NULL UNIQUE,
  recipient_user_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  notification_type text NOT NULL,
  payload           jsonb NOT NULL,
  status            text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'delivered', 'failed')),
  attempts          integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
  available_at      timestamptz NOT NULL DEFAULT now(),
  created_at        timestamptz NOT NULL DEFAULT now(),
  delivered_at      timestamptz
);
CREATE INDEX notification_outbox_ready_idx ON notification_outbox (available_at, created_at)
  WHERE status IN ('pending', 'failed');

-- Suggested application-level transaction contract for every admin mutation:
-- BEGIN; lock/read target; validate transition and actor; update target; insert
-- admin_audit_logs with secret-redacted snapshots; COMMIT. Dispatch notifications
-- only after commit (prefer a transactional outbox migration before production).
