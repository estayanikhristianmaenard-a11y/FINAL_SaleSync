ALTER TABLE products ADD COLUMN presentation TEXT NOT NULL DEFAULT '{}';
ALTER TABLE seller_profiles ADD COLUMN presentation TEXT NOT NULL DEFAULT '{}';
CREATE TABLE product_variants (
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    variant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    attributes TEXT NOT NULL DEFAULT '{}',
    price_delta INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(product_id, variant_id)
);
INSERT INTO product_variants(product_id,variant_id,name) SELECT product_id,'std','As pictured' FROM products;
ALTER TABLE cart_items RENAME TO cart_items_old;
CREATE TABLE cart_items (
    cart_item_id INTEGER PRIMARY KEY,
    cart_id INTEGER NOT NULL REFERENCES carts(cart_id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    variant_id TEXT NOT NULL DEFAULT 'std',
    quantity INTEGER NOT NULL CHECK(quantity BETWEEN 1 AND 999),
    UNIQUE(cart_id,product_id,variant_id),
    FOREIGN KEY(product_id,variant_id) REFERENCES product_variants(product_id,variant_id)
);
INSERT INTO cart_items(cart_item_id,cart_id,product_id,quantity) SELECT cart_item_id,cart_id,product_id,quantity FROM cart_items_old;
DROP TABLE cart_items_old;
ALTER TABLE order_items ADD COLUMN variant_id TEXT NOT NULL DEFAULT 'std';
ALTER TABLE order_items ADD COLUMN variant_name_snapshot TEXT NOT NULL DEFAULT 'As pictured';
ALTER TABLE order_items ADD COLUMN variant_attributes_snapshot TEXT NOT NULL DEFAULT '{}';
