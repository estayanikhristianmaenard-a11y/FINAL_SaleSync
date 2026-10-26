<?php
declare(strict_types=1);

namespace SaleSync\Payments;

use SaleSync\Core\{ApiError, Database};
use SaleSync\Customer\OrderService;

final class PaymentService
{
    public function __construct(private readonly Database $db, private readonly OrderService $orders, private readonly Gateway $gateway) {}

    public function start(int $userId, int $orderId): array
    {
        $order = $this->orders->get($userId, $orderId);
        if ($order['status'] !== 'pending_payment') { throw new ApiError(409, 'This order is not awaiting payment.'); }
        $payment = $this->db->one('SELECT * FROM payments WHERE order_id=?', [$orderId]);
        if ($payment['provider_reference']) { return ['checkout_url' => $payment['checkout_url'], 'status' => $payment['status']]; }
        // A durable claim prevents concurrent callers and uncertain failures from charging twice.
        $claimed = $this->db->run("UPDATE payments SET status='creating' WHERE order_id=? AND status='pending' AND provider_reference IS NULL AND EXISTS(SELECT 1 FROM orders WHERE order_id=? AND status='pending_payment')", [$orderId, $orderId]);
        if (!$claimed) { throw new ApiError(409, 'Payment creation is pending reconciliation. Do not start another payment.'); }
        try {
            $session = $this->gateway->create($order);
            $reference = $session['id'] ?? '';
            $url = $session['attributes']['checkout_url'] ?? '';
            if (!is_string($reference) || !str_starts_with($reference, 'cs_') || !is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.paymongo.com' || ($session['attributes']['livemode'] ?? null) !== false) {
                throw new ApiError(502, 'Unexpected payment session response. Reconciliation is required.');
            }
            // A signed webhook can arrive before the create response. Never regress paid status.
            $this->db->run("UPDATE payments SET provider_reference=?,checkout_url=?,status=CASE WHEN status IN ('creating','unknown') THEN 'pending' ELSE status END WHERE order_id=? AND (provider_reference IS NULL OR provider_reference=?)", [$reference, $url, $orderId, $reference]);
            return ['checkout_url' => $url, 'status' => $this->db->one('SELECT status FROM payments WHERE order_id=?', [$orderId])['status']];
        } catch (\Throwable $error) {
            $this->db->run("UPDATE payments SET status='unknown' WHERE order_id=? AND status='creating'", [$orderId]);
            throw $error;
        }
    }

    public function refresh(int $userId, int $orderId): array
    {
        $this->orders->get($userId, $orderId);
        $payment = $this->db->one('SELECT * FROM payments WHERE order_id=?', [$orderId]);
        if ($payment['provider_reference'] && $payment['status'] !== 'paid') {
            $this->apply($this->gateway->retrieve($payment['provider_reference']));
        }
        return $this->orders->get($userId, $orderId);
    }

    public function apply(array $session, ?string $eventId = null): void
    {
        $reference = $session['id'] ?? null;
        $attributes = $session['attributes'] ?? [];
        if (!is_string($reference) || !str_starts_with($reference, 'cs_') || ($attributes['livemode'] ?? null) !== false || !is_string($attributes['reference_number'] ?? null)) {
            throw new ApiError(422, 'Invalid test payment session.');
        }
        $this->db->transaction(function () use ($reference, $attributes, $eventId): void {
            if ($eventId && $this->db->one('SELECT event_id FROM payment_events WHERE event_id=?', [$eventId])) { return; }
            $record = $this->db->one('SELECT p.*,o.order_number,o.status AS order_status,o.customer_id FROM payments p JOIN orders o ON o.order_id=p.order_id WHERE o.order_number=?', [$attributes['reference_number']]);
            if (!$record) { throw new ApiError(404, 'Payment order not found.'); }
            if ($record['provider_reference'] !== null && $record['provider_reference'] !== $reference) { throw new ApiError(409, 'Payment reference does not match the order.'); }
            if ($record['provider_reference'] === null && !in_array($record['status'], ['creating', 'unknown'], true)) { throw new ApiError(409, 'No payment session was requested for this order.'); }
            $paid = null;
            foreach (($attributes['payments'] ?? []) as $payment) {
                if (($payment['attributes']['status'] ?? null) === 'paid') { $paid = $payment['attributes']; break; }
            }
            if ($paid && (($paid['amount'] ?? null) !== $record['amount'] || ($paid['currency'] ?? null) !== 'PHP' || ($paid['livemode'] ?? false) !== false)) {
                throw new ApiError(422, 'Payment amount, currency or mode does not match the order.');
            }
            if ($record['order_status'] === 'cancelled' && $paid) { throw new ApiError(409, 'Paid event received for a cancelled order; manual review required.'); }
            $url = $attributes['checkout_url'] ?? null;
            if ($url !== null && (!is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.paymongo.com')) {
                throw new ApiError(422, 'Unexpected payment checkout URL.');
            }
            $this->db->run("UPDATE payments SET provider_reference=?,checkout_url=COALESCE(?,checkout_url),status=CASE WHEN status IN ('creating','unknown') THEN 'pending' ELSE status END WHERE payment_id=?", [$reference, $url, $record['payment_id']]);
            if ($paid && $record['status'] !== 'paid') {
                $this->db->run("UPDATE payments SET status='paid',paid_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE payment_id=?", [$record['payment_id']]);
                $this->db->run("UPDATE orders SET status='processing' WHERE order_id=? AND status='pending_payment'", [$record['order_id']]);
                $this->db->run("INSERT INTO order_status_history(order_id,status) VALUES (?,'processing')", [$record['order_id']]);
                $this->db->run('INSERT INTO notifications(user_id,title,message) VALUES (?,?,?)', [$record['customer_id'], 'Payment confirmed', $record['order_number'] . ' is paid and ready for processing.']);
            } elseif (!$paid && ($attributes['status'] ?? '') === 'expired' && $record['status'] !== 'paid') {
                $this->orders->release((int) $record['order_id']);
            }
            if ($eventId) { $this->db->run('INSERT INTO payment_events(event_id,provider_reference) VALUES (?,?)', [$eventId, $reference]); }
        });
    }
}
