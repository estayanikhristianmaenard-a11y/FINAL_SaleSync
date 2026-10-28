<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError, Database, Input};

final class CartService
{
    public function __construct(private readonly Database $db, private readonly int $shippingFee) {}

    public function get(int $userId): array
    {
        $items = $this->db->all('SELECT p.product_id,p.name,p.sku,p.category_id,p.presentation,p.price+v.price_delta AS price,p.stock_qty,p.image_url,p.seller_id,s.store_name,ci.variant_id,v.name AS variant_name,v.attributes AS variant_attributes,ci.quantity,(' . CatalogService::VISIBLE . ') AS available FROM cart_items ci JOIN carts c ON c.cart_id=ci.cart_id JOIN products p ON p.product_id=ci.product_id JOIN product_variants v ON v.product_id=ci.product_id AND v.variant_id=ci.variant_id JOIN seller_profiles s ON s.seller_id=p.seller_id JOIN users u ON u.user_id=s.user_id WHERE c.customer_id=? ORDER BY ci.cart_item_id', [$userId]);
        $subtotal = 0;
        foreach ($items as &$item) {
            $item['line_total'] = $item['price'] * $item['quantity'];
            $item['available'] = (bool) $item['available'] && $item['stock_qty'] >= $item['quantity'] && $item['price'] > 0;
            $item['presentation'] = json_decode($item['presentation'], true, 512, JSON_THROW_ON_ERROR);
            $item['variant_attributes'] = json_decode($item['variant_attributes'], true, 512, JSON_THROW_ON_ERROR);
            $subtotal += $item['line_total'];
        }
        return ['items' => $items, 'subtotal' => $subtotal, 'shipping_fee' => $items ? $this->shippingFee : 0, 'total' => $subtotal + ($items ? $this->shippingFee : 0), 'currency' => 'PHP'];
    }

    public function quote(int $userId, ?array $selection = null, array $vouchers = [], bool $issueToken = true): array
    {
        $items = $this->get($userId)['items'];
        if ($selection !== null) {
            if (!$selection || count($selection) > 100) { throw new ApiError(422, 'Select 1 to 100 cart options.'); }
            $keys = [];
            foreach ($selection as $entry) {
                if (!is_array($entry)) { throw new ApiError(422, 'Invalid cart selection.'); }
                $productId = Input::integer($entry, 'product_id', 1, PHP_INT_MAX);
                $variantId = Input::text($entry, 'variant_id', 100);
                $keys[$productId . ':' . $variantId] = true;
            }
            $items = array_values(array_filter($items, static fn(array $item): bool => isset($keys[$item['product_id'] . ':' . $item['variant_id']])));
            if (count($items) !== count($keys)) { throw new ApiError(409, 'Selected cart items changed. Refresh your cart.'); }
        }
        if (!$items) { throw new ApiError(422, 'Your cart is empty.'); }
        $quantities = [];
        foreach ($items as $item) {
            $quantities[$item['product_id']] = ($quantities[$item['product_id']] ?? 0) + $item['quantity'];
            if (!$item['available'] || $quantities[$item['product_id']] > $item['stock_qty']) { throw new ApiError(409, 'A selected product is unavailable or has insufficient stock.'); }
        }
        $subtotal = array_sum(array_column($items, 'line_total'));
        $quote = (new VoucherService($this->db))->apply($userId,['items' => $items, 'subtotal' => $subtotal, 'shipping_fee' => $this->shippingFee, 'total' => $subtotal + $this->shippingFee, 'currency' => 'PHP'],$vouchers);
        return $issueToken ? (new CheckoutQuote($this->db))->issue($userId, $quote) : $quote;
    }

    public function set(int $userId, int $productId, int $quantity, string $variantId = 'std'): array
    {
        return $this->db->transaction(function () use ($userId, $productId, $quantity, $variantId): array {
            $product = (new CatalogService($this->db))->product($productId);
            $variant = $this->db->one('SELECT * FROM product_variants WHERE product_id=? AND variant_id=?', [$productId, $variantId]);
            if (!$variant || $product['price'] + $variant['price_delta'] <= 0) { throw new ApiError(422, 'Choose a valid product option.'); }
            $this->db->run('INSERT INTO carts(customer_id) VALUES (?) ON CONFLICT(customer_id) DO NOTHING', [$userId]);
            $cart = $this->db->one('SELECT cart_id FROM carts WHERE customer_id=?', [$userId]);
            $other = $this->db->one('SELECT COALESCE(SUM(quantity),0) AS quantity FROM cart_items WHERE cart_id=? AND product_id=? AND variant_id!=?', [$cart['cart_id'], $productId, $variantId]);
            if ($quantity + $other['quantity'] > $product['stock_qty']) { throw new ApiError(409, 'Requested quantity across product options exceeds stock.'); }
            $count = $this->db->one('SELECT COUNT(*) AS count FROM cart_items WHERE cart_id=?', [$cart['cart_id']])['count'];
            if ($count >= 100 && !$this->db->one('SELECT cart_item_id FROM cart_items WHERE cart_id=? AND product_id=? AND variant_id=?', [$cart['cart_id'], $productId, $variantId])) { throw new ApiError(422, 'Cart is limited to 100 product options.'); }
            $this->db->run('INSERT INTO cart_items(cart_id,product_id,variant_id,quantity) VALUES (?,?,?,?) ON CONFLICT(cart_id,product_id,variant_id) DO UPDATE SET quantity=excluded.quantity', [$cart['cart_id'], $productId, $variantId, $quantity]);
            $this->touch($userId);
            return $this->get($userId);
        });
    }

    private function touch(int $userId): void
    {
        $this->db->run("UPDATE carts SET updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE customer_id=?", [$userId]);
    }

    public function remove(int $userId, int $productId, string $variantId = 'std'): array
    {
        $this->db->run('DELETE FROM cart_items WHERE product_id=? AND variant_id=? AND cart_id=(SELECT cart_id FROM carts WHERE customer_id=?)', [$productId, $variantId, $userId]);
        $this->touch($userId);
        return $this->get($userId);
    }
}
