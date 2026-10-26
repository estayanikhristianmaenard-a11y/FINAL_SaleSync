<?php
declare(strict_types=1);

namespace SaleSync\Payments;

use SaleSync\Core\ApiError;

final class PayMongoGateway implements Gateway
{
    public function __construct(private readonly array $config) {}

    public function ready(): bool
    {
        return str_starts_with($this->config['paymongo_secret'], 'sk_test_');
    }

    public function create(array $order): array
    {
        $items = array_map(static fn(array $item): array => ['name' => $item['product_name_snapshot'], 'amount' => $item['unit_price'], 'quantity' => $item['quantity'], 'currency' => 'PHP'], $order['items']);
        if ($order['shipping_fee']) { $items[] = ['name' => 'Delivery', 'amount' => $order['shipping_fee'], 'quantity' => 1, 'currency' => 'PHP']; }
        if (($order['merchandise_discount'] ?? 0)+($order['shipping_discount'] ?? 0)>0) {
            $items=[['name'=>'SaleSync order '.$order['order_number'].' (vouchers applied)','amount'=>$order['total'],'quantity'=>1,'currency'=>'PHP']];
        }
        $url = rtrim($this->config['app_url'], '/') . '/#orders/' . $order['order_id'];
        return $this->request('POST', '/v2/checkout_sessions', ['data' => ['attributes' => [
            'line_items' => $items,
            'payment_method_types' => $this->config['payment_methods'],
            'reference_number' => $order['order_number'],
            'success_url' => $url,
            'cancel_url' => $url,
            'send_email_receipt' => true,
            'pass_on_fees' => false,
        ]]]);
    }

    public function retrieve(string $reference): array
    {
        return $this->request('GET', '/v1/checkout_sessions/' . rawurlencode($reference));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->ready()) { throw new ApiError(503, 'PayMongo test credentials are not configured.'); }
        $handle = curl_init('https://api.paymongo.com' . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->config['paymongo_secret'] . ':',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($raw === false || $status >= 500 || !$status) {
            throw new ApiError(502, 'Payment provider response is uncertain. Do not create a second payment; reconcile this order first.');
        }
        if ($status >= 400) { throw new ApiError(502, 'PayMongo rejected the request. Check test credentials and enabled payment methods.'); }
        $response = json_decode($raw, true);
        if (!is_array($response['data'] ?? null)) { throw new ApiError(502, 'Payment provider returned an unexpected response.'); }
        return $response['data'];
    }
}
