CREATE TABLE users (
    user_id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'customer' CHECK(role IN ('customer','seller','admin_maintenance','admin_management')),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
    email_verified_at TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE email_verifications (
    user_id INTEGER PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at INTEGER NOT NULL,
    sent_at INTEGER NOT NULL
);
CREATE TABLE login_attempts (bucket TEXT PRIMARY KEY, attempts INTEGER NOT NULL, reset_at INTEGER NOT NULL);
CREATE TABLE seller_profiles (
    seller_id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE REFERENCES users(user_id),
    store_name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    approval_status TEXT NOT NULL DEFAULT 'pending' CHECK(approval_status IN ('pending','approved','rejected'))
);
CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT NOT NULL UNIQUE);
CREATE TABLE products (
    product_id INTEGER PRIMARY KEY,
    seller_id INTEGER NOT NULL REFERENCES seller_profiles(seller_id),
    category_id INTEGER NOT NULL REFERENCES categories(category_id),
    sku TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    price INTEGER NOT NULL CHECK(price > 0),
    stock_qty INTEGER NOT NULL DEFAULT 0 CHECK(stock_qty >= 0),
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','archived')),
    image_url TEXT
);
CREATE TABLE addresses (
    address_id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id),
    label TEXT NOT NULL DEFAULT 'Home',
    recipient_name TEXT NOT NULL,
    phone TEXT NOT NULL,
    address_text TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0 CHECK(is_default IN (0,1))
);
CREATE UNIQUE INDEX one_default_address ON addresses(user_id) WHERE is_default = 1;
CREATE TABLE carts (
    cart_id INTEGER PRIMARY KEY,
    customer_id INTEGER NOT NULL UNIQUE REFERENCES users(user_id),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE cart_items (
    cart_item_id INTEGER PRIMARY KEY,
    cart_id INTEGER NOT NULL REFERENCES carts(cart_id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    quantity INTEGER NOT NULL CHECK(quantity BETWEEN 1 AND 999),
    UNIQUE(cart_id, product_id)
);
CREATE TABLE orders (
    order_id INTEGER PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES users(user_id),
    order_number TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending_payment' CHECK(status IN ('pending_payment','processing','ready_for_shipment','shipped','completed','cancelled')),
    address_snapshot TEXT NOT NULL,
    subtotal INTEGER NOT NULL CHECK(subtotal > 0),
    shipping_fee INTEGER NOT NULL CHECK(shipping_fee >= 0),
    total INTEGER NOT NULL CHECK(total = subtotal + shipping_fee),
    checkout_key TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    UNIQUE(customer_id, checkout_key)
);
CREATE INDEX customer_order_history ON orders(customer_id, order_id);
CREATE TABLE order_items (
    order_item_id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL REFERENCES orders(order_id),
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    seller_id INTEGER NOT NULL REFERENCES seller_profiles(seller_id),
    product_name_snapshot TEXT NOT NULL,
    unit_price INTEGER NOT NULL CHECK(unit_price > 0),
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    line_total INTEGER NOT NULL CHECK(line_total = unit_price * quantity)
);
CREATE TABLE payments (
    payment_id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL UNIQUE REFERENCES orders(order_id),
    provider_reference TEXT UNIQUE,
    checkout_url TEXT,
    amount INTEGER NOT NULL CHECK(amount > 0),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','creating','unknown','paid','expired')),
    paid_at TEXT
);
CREATE TABLE order_status_history (
    history_id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL REFERENCES orders(order_id),
    status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE notifications (
    notification_id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id),
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    read_at TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX customer_notifications ON notifications(user_id, notification_id);
CREATE TABLE payment_events (
    event_id TEXT PRIMARY KEY,
    provider_reference TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
