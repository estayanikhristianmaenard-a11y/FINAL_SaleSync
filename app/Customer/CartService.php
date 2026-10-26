<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError, Database};

final class CartService
{
    public function __construct(private readonly Database $db, private readonly int $shippingFee) {}

    public function get(int $userId): array
    {
        $items = $this->db->all('SELECT p.product_id,p.name,p.price,p.stock_qty,p.image_url,p.seller_id,s.store_name,ci.quantity,(' . CatalogService::VISIBLE . ') AS available FROM cart_items ci JOIN carts c ON c.cart_id=ci.cart_id JOIN products p ON p.product_id=ci.product_id JOIN seller_profiles s ON s.seller_id=p.seller_id JOIN users u ON u.user_id=s.user_id WHERE c.customer_id=? ORDER BY ci.cart_item_id', [$userId]);
        $subtotal = 0;
        foreach ($items as &$item) {
            $item['line_total'] = $item['price'] * $item['quantity'];
            $item['available'] = (bool) $item['available'] && $item['stock_qty'] >= $item['quantity'];
            $subtotal += $item['line_total'];
        }
        return ['items' => $items, 'subtotal' => $subtotal, 'shipping_fee' => $items ? $this->shippingFee : 0, 'total' => $subtotal + ($items ? $this->shippingFee : 0), 'currency' => 'PHP'];
    }

    public function set(int $userId, int $productId, int $quantity): array
    {
        return $this->db->transaction(function () use ($userId, $productId, $quantity): array {
            $product = (new CatalogService($this->db))->product($productId);
            if ($quantity > $product['stock_qty']) { throw new ApiError(409, 'Requested quantity is no longer in stock.'); }
            $this->db->run('INSERT INTO carts(customer_id) VALUES (?) ON CONFLICT(customer_id) DO NOTHING', [$userId]);
            $cart = $this->db->one('SELECT cart_id FROM carts WHERE customer_id=?', [$userId]);
            $count = $this->db->one('SELECT COUNT(*) AS count FROM cart_items WHERE cart_id=?', [$cart['cart_id']])['count'];
            if ($count >= 100 && !$this->db->one('SELECT cart_item_id FROM cart_items WHERE cart_id=? AND product_id=?', [$cart['cart_id'], $productId])) { throw new ApiError(422, 'Cart is limited to 100 different products.'); }
            $this->db->run('INSERT INTO cart_items(cart_id,product_id,quantity) VALUES (?,?,?) ON CONFLICT(cart_id,product_id) DO UPDATE SET quantity=excluded.quantity', [$cart['cart_id'], $productId, $quantity]);
            $this->touch($userId);
            return $this->get($userId);
        });
    }

    private function touch(int $userId): void
    {
        $this->db->run("UPDATE carts SET updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE customer_id=?", [$userId]);
    }

    public function remove(int $userId, int $productId): array
    {
        $this->db->run('DELETE FROM cart_items WHERE product_id=? AND cart_id=(SELECT cart_id FROM carts WHERE customer_id=?)', [$productId, $userId]);
        $this->touch($userId);
        return $this->get($userId);
    }
}
