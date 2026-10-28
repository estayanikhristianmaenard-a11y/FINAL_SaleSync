<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError, Database};

/** Binds checkout to the server-calculated basket the customer reviewed. */
final class CheckoutQuote
{
    public function __construct(private readonly Database $db) {}

    public function issue(int $userId, array $quote): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + 900;
        $this->db->run('DELETE FROM checkout_quotes WHERE expires_at < ?', [time()]);
        $this->db->run('INSERT INTO checkout_quotes(token_hash,customer_id,fingerprint,expires_at) VALUES (?,?,?,?)',
            [hash('sha256', $token), $userId, self::fingerprint($quote), $expires]);
        return array_replace($quote, ['quote_token' => $token, 'quote_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires)]);
    }

    public function verify(int $userId, mixed $token, array $quote): void
    {
        $record = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
            ? $this->db->one('SELECT fingerprint,expires_at FROM checkout_quotes WHERE token_hash=? AND customer_id=?', [hash('sha256', $token), $userId])
            : null;
        if (!$record || $record['expires_at'] <= time()) {
            throw new ApiError(409, 'Your checkout quote expired. Review the updated total and place your order again.', ['quote' => 'expired']);
        }
        if (!hash_equals($record['fingerprint'], self::fingerprint($quote))) {
            throw new ApiError(409, 'Prices or selected items changed. Review the updated checkout total before placing your order again.', ['quote' => 'changed']);
        }
    }

    private static function fingerprint(array $quote): string
    {
        $items = array_map(static fn(array $item): array => [
            $item['product_id'], $item['seller_id'], $item['variant_id'], $item['name'],
            $item['price'], $item['quantity'], $item['line_total'], $item['discount_amount'],
        ], $quote['items']);
        $vouchers = array_map(static fn(array $voucher): array => [
            $voucher['voucher_id'], $voucher['discount'], $voucher['scope'], $voucher['seller_id'],
        ], $quote['applied_vouchers']);
        return hash('sha256', json_encode([
            $items, $quote['subtotal'], $quote['shipping_fee'], $quote['merchandise_discount'],
            $quote['shipping_discount'], $quote['total'], $quote['currency'], $vouchers,
        ], JSON_THROW_ON_ERROR));
    }
}
