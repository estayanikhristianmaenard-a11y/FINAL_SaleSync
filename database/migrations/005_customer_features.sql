ALTER TABLE users ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 0;
CREATE TABLE orders_updated (
    order_id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES users(user_id), order_number TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending_payment' CHECK(status IN('pending_payment','processing','ready_for_shipment','shipped','completed','cancelled')),
    address_snapshot TEXT NOT NULL, subtotal INTEGER NOT NULL CHECK(subtotal>0), shipping_fee INTEGER NOT NULL CHECK(shipping_fee>=0),
    merchandise_discount INTEGER NOT NULL DEFAULT 0 CHECK(merchandise_discount BETWEEN 0 AND subtotal),
    shipping_discount INTEGER NOT NULL DEFAULT 0 CHECK(shipping_discount BETWEEN 0 AND shipping_fee),
    total INTEGER NOT NULL CHECK(total=subtotal+shipping_fee-merchandise_discount-shipping_discount AND total>=0),
    checkout_key TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')), UNIQUE(customer_id,checkout_key)
);
INSERT INTO orders_updated(order_id,customer_id,order_number,status,address_snapshot,subtotal,shipping_fee,total,checkout_key,created_at) SELECT order_id,customer_id,order_number,status,address_snapshot,subtotal,shipping_fee,total,checkout_key,created_at FROM orders;
DROP TABLE orders;
ALTER TABLE orders_updated RENAME TO orders;
CREATE INDEX customer_order_history ON orders(customer_id,order_id);
CREATE TABLE saved_products (customer_id INTEGER NOT NULL REFERENCES users(user_id), product_id INTEGER NOT NULL REFERENCES products(product_id), created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')), PRIMARY KEY(customer_id,product_id));
CREATE TABLE notification_preferences (customer_id INTEGER PRIMARY KEY REFERENCES users(user_id), orders INTEGER NOT NULL DEFAULT 1 CHECK(orders IN(0,1)), messages INTEGER NOT NULL DEFAULT 1 CHECK(messages IN(0,1)), promotions INTEGER NOT NULL DEFAULT 0 CHECK(promotions IN(0,1)));
ALTER TABLE notifications ADD COLUMN type TEXT NOT NULL DEFAULT 'Order';
ALTER TABLE notifications ADD COLUMN context_type TEXT;
ALTER TABLE notifications ADD COLUMN context_id INTEGER;
CREATE TABLE notification_outbox (outbox_id INTEGER PRIMARY KEY, notification_id INTEGER NOT NULL UNIQUE REFERENCES notifications(notification_id), status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN('queued','sent','logged','skipped','failed')), attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')));
CREATE TABLE vouchers (
    voucher_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE COLLATE NOCASE, name TEXT NOT NULL,
    scope TEXT NOT NULL CHECK(scope IN('platform','shop','shipping')), seller_id INTEGER REFERENCES seller_profiles(seller_id),
    kind TEXT NOT NULL CHECK(kind IN('fixed','percent')), value INTEGER NOT NULL CHECK(value>0),
    max_discount INTEGER CHECK(max_discount>0), minimum_spend INTEGER NOT NULL DEFAULT 0 CHECK(minimum_spend>=0),
    starts_at TEXT NOT NULL, expires_at TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 0 CHECK(active IN(0,1)),
    usage_limit INTEGER CHECK(usage_limit>0), CHECK(expires_at>starts_at),
    CHECK((scope='shop' AND seller_id IS NOT NULL) OR (scope!='shop' AND seller_id IS NULL)), CHECK(kind!='percent' OR value<=100)
);
CREATE TABLE voucher_claims (claim_id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES users(user_id), voucher_id INTEGER NOT NULL REFERENCES vouchers(voucher_id), claimed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')), UNIQUE(customer_id,voucher_id));
CREATE TABLE voucher_redemptions (redemption_id INTEGER PRIMARY KEY, claim_id INTEGER NOT NULL UNIQUE REFERENCES voucher_claims(claim_id), voucher_id INTEGER NOT NULL REFERENCES vouchers(voucher_id), order_id INTEGER NOT NULL REFERENCES orders(order_id), discount INTEGER NOT NULL CHECK(discount>=0), scope TEXT NOT NULL, seller_id INTEGER REFERENCES seller_profiles(seller_id));
CREATE INDEX voucher_usage ON voucher_redemptions(voucher_id);
ALTER TABLE order_items ADD COLUMN discount_amount INTEGER NOT NULL DEFAULT 0;
CREATE TABLE customer_threads (thread_id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES users(user_id), seller_id INTEGER NOT NULL REFERENCES seller_profiles(seller_id), created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')), UNIQUE(customer_id,seller_id));
CREATE TABLE customer_messages (message_id INTEGER PRIMARY KEY, thread_id INTEGER NOT NULL REFERENCES customer_threads(thread_id), sender_id INTEGER NOT NULL REFERENCES users(user_id), body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 2000), client_key TEXT NOT NULL, read_at TEXT, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')), UNIQUE(sender_id,client_key));
CREATE INDEX thread_message_order ON customer_messages(thread_id,message_id);
CREATE TABLE customer_uploads (upload_id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES users(user_id), storage_name TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, media_type TEXT NOT NULL, byte_size INTEGER NOT NULL, purpose TEXT NOT NULL CHECK(purpose IN('return','application')), created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')));
CREATE TABLE return_cases (case_id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES users(user_id), order_id INTEGER NOT NULL REFERENCES orders(order_id), order_item_id INTEGER NOT NULL REFERENCES order_items(order_item_id), quantity INTEGER NOT NULL CHECK(quantity>0), reason TEXT NOT NULL, explanation TEXT NOT NULL, solution TEXT NOT NULL CHECK(solution IN('return_refund','refund_only')), status TEXT NOT NULL DEFAULT 'requested' CHECK(status IN('requested','approved','rejected','returned','refund_pending','refunded','escalated','management_approved','refund_denied')), eligible_amount INTEGER NOT NULL CHECK(eligible_amount>=0), seller_reply TEXT, client_key TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')), UNIQUE(customer_id,client_key));
CREATE TABLE return_events (event_id INTEGER PRIMARY KEY, case_id INTEGER NOT NULL REFERENCES return_cases(case_id), actor_id INTEGER NOT NULL REFERENCES users(user_id), status TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')));
CREATE TABLE return_evidence (case_id INTEGER NOT NULL REFERENCES return_cases(case_id), upload_id INTEGER NOT NULL UNIQUE REFERENCES customer_uploads(upload_id), event_id INTEGER NOT NULL REFERENCES return_events(event_id), PRIMARY KEY(case_id,upload_id));
CREATE TABLE seller_applications (application_id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES users(user_id), store_name TEXT NOT NULL, business_name TEXT NOT NULL, business_type TEXT NOT NULL, contact_name TEXT NOT NULL, contact_email TEXT NOT NULL, phone TEXT NOT NULL, address_text TEXT NOT NULL, description TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN('pending','needs_information','approved','rejected','suspended')), submitted_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')), updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')));
CREATE UNIQUE INDEX one_open_application ON seller_applications(customer_id) WHERE status IN('pending','needs_information','approved','suspended');
CREATE TABLE application_events (event_id INTEGER PRIMARY KEY, application_id INTEGER NOT NULL REFERENCES seller_applications(application_id), actor_id INTEGER NOT NULL REFERENCES users(user_id), status TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')));
CREATE TABLE application_documents (application_id INTEGER NOT NULL REFERENCES seller_applications(application_id), upload_id INTEGER NOT NULL UNIQUE REFERENCES customer_uploads(upload_id), PRIMARY KEY(application_id,upload_id));
