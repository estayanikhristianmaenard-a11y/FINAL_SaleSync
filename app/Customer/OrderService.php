<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError, Database, Input};

final class OrderService
{
    public function __construct(private readonly Database $db, private readonly CartService $cart) {}

    public function checkout(int $userId, array $input, string $key): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key)) { throw new ApiError(422, 'Supply an Idempotency-Key header of 16–100 letters, numbers, hyphens or underscores.'); }
        $addressId = Input::integer($input, 'address_id', 1, PHP_INT_MAX);
        return $this->db->transaction(function () use ($userId, $addressId, $key): array {
            $existing = $this->db->one('SELECT order_id FROM orders WHERE customer_id=? AND checkout_key=?', [$userId, $key]);
            if ($existing) { return $this->get($userId, (int) $existing['order_id']); }
            $address = (new ProfileService($this->db))->address($userId, $addressId);
            unset($address['user_id'], $address['is_default'], $address['address_id']);
            $cart = $this->cart->get($userId);
            if (!$cart['items']) { throw new ApiError(422, 'Your cart is empty.'); }
            foreach ($cart['items'] as $item) {
                if (!$item['available']) { throw new ApiError(409, 'A product in your cart is unavailable. Refresh your cart.'); }
            }
            $number = 'SS-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
            $this->db->run('INSERT INTO orders(customer_id,order_number,address_snapshot,subtotal,shipping_fee,total,checkout_key) VALUES (?,?,?,?,?,?,?)', [$userId, $number, json_encode($address, JSON_THROW_ON_ERROR), $cart['subtotal'], $cart['shipping_fee'], $cart['total'], $key]);
            $id = $this->db->id();
            foreach ($cart['items'] as $item) {
                $updated = $this->db->run('UPDATE products SET stock_qty=stock_qty-? WHERE product_id=? AND stock_qty>=?', [$item['quantity'], $item['product_id'], $item['quantity']]);
                if (!$updated) { throw new ApiError(409, 'Stock changed. Refresh your cart.'); }
                $this->db->run('INSERT INTO order_items(order_id,product_id,seller_id,product_name_snapshot,unit_price,quantity,line_total) VALUES (?,?,?,?,?,?,?)', [$id, $item['product_id'], $item['seller_id'], $item['name'], $item['price'], $item['quantity'], $item['line_total']]);
            }
            $this->db->run('INSERT INTO payments(order_id,amount) VALUES (?,?)', [$id, $cart['total']]);
            $this->db->run("INSERT INTO order_status_history(order_id,status) VALUES (?,'pending_payment')", [$id]);
            $this->db->run('INSERT INTO notifications(user_id,title,message) VALUES (?,?,?)', [$userId, 'Order created', "$number is awaiting payment."]);
            $this->db->run('DELETE FROM cart_items WHERE cart_id=(SELECT cart_id FROM carts WHERE customer_id=?)', [$userId]);
            return $this->get($userId, $id);
        });
    }

    public function get(int $userId, int $id): array
    {
        $order = $this->db->one('SELECT order_id,order_number,status,address_snapshot,subtotal,shipping_fee,total,created_at FROM orders WHERE order_id=? AND customer_id=?', [$id, $userId]) ?? throw new ApiError(404, 'Order not found.');
        $order['address'] = json_decode($order['address_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        unset($order['address_snapshot']);
        $order['currency'] = 'PHP';
        $order['items'] = $this->db->all('SELECT product_id,seller_id,product_name_snapshot,unit_price,quantity,line_total FROM order_items WHERE order_id=? ORDER BY order_item_id', [$id]);
        $order['payment'] = $this->db->one('SELECT status,amount,checkout_url,paid_at FROM payments WHERE order_id=?', [$id]);
        $order['history'] = $this->db->all('SELECT status,created_at FROM order_status_history WHERE order_id=? ORDER BY history_id', [$id]);
        return $order;
    }

    public function list(int $userId, array $query): array
    {
        $page = isset($query['page']) ? Input::integer($query, 'page', 1, 100000) : 1;
        $items = $this->db->all('SELECT o.order_id,o.order_number,o.status,o.subtotal,o.shipping_fee,o.total,o.created_at,p.status AS payment_status FROM orders o JOIN payments p ON p.order_id=o.order_id WHERE o.customer_id=? ORDER BY o.order_id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), [$userId]);
        return ['items' => $items, 'page' => $page, 'total' => $this->db->one('SELECT COUNT(*) AS count FROM orders WHERE customer_id=?', [$userId])['count']];
    }

    public function cancel(int $userId, int $id): array
    {
        return $this->db->transaction(function () use ($userId, $id): array {
            $order = $this->get($userId, $id);
            if ($order['status'] === 'cancelled') { return $order; }
            $payment = $this->db->one('SELECT * FROM payments WHERE order_id=?', [$id]);
            // Once a provider session exists, only a verified expiry may release its reservation.
            if ($order['status'] !== 'pending_payment' || $payment['provider_reference'] || in_array($payment['status'], ['creating', 'unknown', 'paid'], true)) { throw new ApiError(409, 'This order cannot be cancelled while a payment session may be active.'); }
            $this->release($id);
            return $this->get($userId, $id);
        });
    }

    // Must be called inside a database transaction, after confirming cancellation/expiry is safe.
    public function release(int $id): void
    {
        $changed = $this->db->run("UPDATE orders SET status='cancelled' WHERE order_id=? AND status='pending_payment'", [$id]);
        if (!$changed) { return; }
        foreach ($this->db->all('SELECT product_id,quantity FROM order_items WHERE order_id=?', [$id]) as $item) {
            $this->db->run('UPDATE products SET stock_qty=stock_qty+? WHERE product_id=?', [$item['quantity'], $item['product_id']]);
        }
        $this->db->run("UPDATE payments SET status='expired' WHERE order_id=? AND status!='paid'", [$id]);
        $this->db->run("INSERT INTO order_status_history(order_id,status) VALUES (?,'cancelled')", [$id]);
        $this->db->run("INSERT INTO notifications(user_id,title,message) SELECT customer_id,'Order cancelled',order_number || ' was cancelled; reserved stock has been released.' FROM orders WHERE order_id=?", [$id]);
    }
}
