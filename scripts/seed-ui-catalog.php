<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = new SaleSync\Core\Database($config['database']);
$catalog = json_decode(file_get_contents(dirname(__DIR__) . '/database/fixtures/customer-ui-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$db->transaction(function () use ($db, $catalog): void {
    $stores = [];
    foreach ($catalog['stores'] as $store) {
        $email = 'ui-' . $store['id'] . '@example.test';
        $user = $db->one('SELECT user_id FROM users WHERE email=?', [$email]);
        if (!$user) {
            $db->run("INSERT INTO users(name,email,password_hash,role) VALUES (?,?,?,'seller')", [$store['name'], $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
            $user = ['user_id' => $db->id()];
        }
        $existing = $db->one('SELECT seller_id FROM seller_profiles WHERE user_id=?', [$user['user_id']]);
        if (!$existing) {
            $presentation = array_intersect_key($store, array_flip(['category','brand','brandReference','identity','location','badge','sourceUrl']));
            $presentation['ui_key'] = $store['id'];
            $db->run("INSERT INTO seller_profiles(user_id,store_name,description,approval_status,presentation) VALUES (?,?,?,'approved',?)", [$user['user_id'], $store['name'], $store['description'], json_encode($presentation, JSON_THROW_ON_ERROR)]);
            $existing = ['seller_id' => $db->id()];
        }
        $stores[$store['id']] = $existing['seller_id'];
    }
    $added = 0;
    foreach ($catalog['products'] as $product) {
        $sku = 'UI-' . $product['sku'];
        if ($db->one('SELECT product_id FROM products WHERE sku=?', [$sku])) { continue; }
        $db->run('INSERT INTO categories(category_name) VALUES (?) ON CONFLICT(category_name) DO NOTHING', [$product['category']]);
        $category = $db->one('SELECT category_id FROM categories WHERE category_name=?', [$product['category']]);
        $presentation = array_intersect_key($product, array_flip(['type','brand','brandReference','images','specs','productPhotoId','sourceUrl','description']));
        $presentation['ui_key'] = $product['id'];
        // Prices/options are persisted as server values. Sample reviews and sales are not imported.
        $price = (int) round($product['price'] * 100);
        $image = isset($product['images'][0]['src']) ? 'assets/ui/' . $product['images'][0]['src'] : null;
        $db->run("INSERT INTO products(seller_id,category_id,sku,name,description,price,stock_qty,status,image_url,presentation) VALUES (?,?,?,?,?,?,?,'published',?,?)", [$stores[$product['storeId']], $category['category_id'], $sku, $product['name'], $product['description'] ?? $product['name'], $price, max(0, (int) $product['base']), $image, json_encode($presentation, JSON_THROW_ON_ERROR)]);
        $productId = $db->id();
        foreach ($product['variants'] as $variant) {
            $db->run('INSERT INTO product_variants(product_id,variant_id,name,attributes,price_delta) VALUES (?,?,?,?,?)', [$productId, $variant['id'], $variant['name'], json_encode($variant['attributes'] ?? new stdClass(), JSON_THROW_ON_ERROR), (int) round(($variant['add'] ?? 0) * 100)]);
        }
        $added++;
    }
    if (in_array('--archive-demo', $GLOBALS['argv'], true)) {
        // Reversible: retain all original IDs and order snapshots; hide only our earlier demo fixtures.
        $db->run("UPDATE products SET status='archived' WHERE sku LIKE 'DEMO-%' AND seller_id IN (SELECT s.seller_id FROM seller_profiles s JOIN users u ON u.user_id=s.user_id WHERE u.email='demo-store@example.test')");
    }
    echo "Imported $added customer catalogue fixtures; existing catalogue records were preserved.\n";
});
