<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = new SaleSync\Core\Database($config['database']);
$db->transaction(function () use ($db): void {
    if ($db->one('SELECT user_id FROM users WHERE email=?', ['demo-store@example.test'])) { echo "Demo catalogue already exists.\n"; return; }
    // Catalogue fixtures only. No shared password or customer account is seeded.
    $db->run("INSERT INTO users(name,email,password_hash,role,email_verified_at) VALUES (?,?,?,'seller',strftime('%Y-%m-%dT%H:%M:%SZ','now'))", ['Demo seller', 'demo-store@example.test', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
    $userId = $db->id();
    $db->run("INSERT INTO seller_profiles(user_id,store_name,description,approval_status) VALUES (?,?,?,'approved')", [$userId, 'Demo Store', 'Development fixtures for customer integration.']);
    $sellerId = $db->id();
    foreach (['Home', 'Accessories', 'Stationery'] as $name) { $db->run('INSERT INTO categories(category_name) VALUES (?) ON CONFLICT(category_name) DO NOTHING', [$name]); }
    $categories = $db->all('SELECT category_id FROM categories ORDER BY category_id');
    foreach (['Canvas Tote', 'Desk Notebook', 'Ceramic Mug', 'Travel Pouch', 'Water Bottle', 'Desk Organizer', 'Cotton Towel', 'Pen Set', 'Storage Basket', 'Lunch Container', 'Key Holder', 'Weekly Planner'] as $index => $name) {
        $db->run("INSERT INTO products(seller_id,category_id,sku,name,description,price,stock_qty,status) VALUES (?,?,?,?,?,?,?,'published')", [$sellerId, $categories[$index % count($categories)]['category_id'], 'DEMO-' . ($index + 1), $name, 'Sample product for frontend integration. Replace with your team catalogue.', 10000 + $index * 2500, 20]);
        $db->run("INSERT INTO product_variants(product_id,variant_id,name) VALUES (?,'std','As pictured')", [$db->id()]);
    }
    echo "Created 12 demo products and one public store.\n";
});
