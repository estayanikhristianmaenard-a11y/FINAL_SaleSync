<?php
declare(strict_types=1);

namespace SaleSync\Http;

use SaleSync\Core\{ApiError, Input, Session};
use SaleSync\Customer\Module;
use SaleSync\Payments\Webhook;

final class Api
{
    public function __construct(private readonly array $config, private readonly ?Module $module = null) {}

    public function dispatch(string $method, string $path, string $raw): mixed
    {
        $module = $this->module ?? new Module($this->config);
        $auth = $module->auth;
        $catalog = $module->catalog;
        $profile = $module->profile;
        $cart = $module->cart;
        $orders = $module->orders;
        $payments = $module->payments;
        $paymentConfigured = $module->gateway->ready();
        if ($method === 'POST' && $path === '/webhooks/paymongo') {
            $event = Webhook::parse($raw, $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '', $this->config['paymongo_webhook_secret']);
            if ($event['type'] === 'checkout_session.payment.paid') {
                $payments->apply($event['session'], $event['id']);
            }
            return ['received' => true];
        }
        Session::start($this->config);
        if (!in_array($method, ['GET', 'HEAD'], true)) { Session::csrf(); }
        $body = [];
        if ($raw !== '') {
            if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) { throw new ApiError(415, 'Send application/json.'); }
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body) || !str_starts_with(ltrim($raw), '{')) { throw new ApiError(422, 'Send a JSON object.'); }
        }
        if ($method === 'GET' && $path === '/session') {
            return ['user' => $auth->current(), 'csrf_token' => $_SESSION['csrf'], 'payment_configured' => $paymentConfigured, 'currency' => 'PHP'];
        }
        if ($method === 'POST' && $path === '/auth/register') { return $auth->register($body); }
        if ($method === 'POST' && $path === '/auth/login') { return $auth->login($body); }
        if ($method === 'POST' && $path === '/auth/logout') { Session::logout(); return ['csrf_token' => $_SESSION['csrf']]; }
        if ($method === 'POST' && $path === '/auth/verify-email') { return $auth->verify($body); }
        if ($method === 'POST' && $path === '/auth/resend-verification') { return $auth->resend(); }
        if ($method === 'GET' && $path === '/products') { return $catalog->products($_GET); }
        if ($method === 'GET' && preg_match('~^/products/([1-9][0-9]*)$~', $path, $match)) { return $catalog->product((int) $match[1]); }
        if ($method === 'GET' && $path === '/categories') { return $catalog->categories(); }
        if ($method === 'GET' && preg_match('~^/stores/([1-9][0-9]*)$~', $path, $match)) { return $catalog->store((int) $match[1]); }

        $user = $auth->customer();
        $id = (int) $user['user_id'];
        if ($method === 'GET' && $path === '/profile') { return $user; }
        if ($method === 'PATCH' && $path === '/profile') { return $profile->update($id, $body); }
        if ($method === 'GET' && $path === '/addresses') { return $profile->addresses($id); }
        if ($method === 'POST' && $path === '/addresses') { return $profile->saveAddress($id, null, $body); }
        if (preg_match('~^/addresses/([1-9][0-9]*)$~', $path, $match)) {
            if ($method === 'PATCH') { return $profile->saveAddress($id, (int) $match[1], $body); }
            if ($method === 'DELETE') { return $profile->deleteAddress($id, (int) $match[1]); }
        }
        if ($method === 'GET' && $path === '/cart') { return $cart->get($id); }
        if (preg_match('~^/cart/items/([1-9][0-9]*)$~', $path, $match)) {
            if ($method === 'PUT') { return $cart->set($id, (int) $match[1], Input::integer($body, 'quantity')); }
            if ($method === 'DELETE') { return $cart->remove($id, (int) $match[1]); }
        }
        if ($method === 'POST' && $path === '/checkout') {
            $auth->customer(true);
            return $orders->checkout($id, $body, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        }
        if ($method === 'GET' && $path === '/orders') { return $orders->list($id, $_GET); }
        if (preg_match('~^/orders/([1-9][0-9]*)(?:/(payment|refresh-payment|cancel))?$~', $path, $match)) {
            $orderId = (int) $match[1];
            $action = $match[2] ?? '';
            if ($method === 'GET' && $action === '') { return $orders->get($id, $orderId); }
            if ($method === 'POST' && $action === 'cancel') { return $orders->cancel($id, $orderId); }
            if ($method === 'POST' && $action === 'refresh-payment') { return $payments->refresh($id, $orderId); }
            if ($method === 'POST' && $action === 'payment') {
                $auth->customer(true);
                if (!$paymentConfigured) { throw new ApiError(503, 'PayMongo test credentials are not configured. Your order can be paid later.'); }
                return $payments->start($id, $orderId);
            }
        }
        if ($method === 'GET' && $path === '/notifications') { return $profile->notifications($id, $_GET); }
        if ($method === 'PATCH' && preg_match('~^/notifications/([1-9][0-9]*)/read$~', $path, $match)) { return $profile->readNotification($id, (int) $match[1]); }
        throw new ApiError(404, 'Endpoint not found.');
    }
}
