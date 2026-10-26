<?php
declare(strict_types=1);

namespace SaleSync\Http;

use SaleSync\Core\{ApiError, Input, Session};
use SaleSync\Customer\{LocationDirectory, Module};
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
            return ['user' => $auth->current(), 'csrf_token' => $_SESSION['csrf'], 'payment_configured' => $paymentConfigured, 'currency' => 'PHP', 'shipping_fee' => $this->config['shipping_fee'], 'return_request_days'=>$this->config['return_request_days']??7, 'minimum_customer_age' => $this->config['minimum_customer_age'] ?? 18, 'registration_date' => (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Manila')))->format('Y-m-d')];
        }
        if ($method === 'POST' && $path === '/auth/register') { return $auth->register($body); }
        if ($method === 'POST' && $path === '/auth/login') { return $auth->login($body); }
        if ($method === 'POST' && $path === '/auth/logout') { Session::logout(); return ['csrf_token' => $_SESSION['csrf']]; }
        if ($method === 'POST' && $path === '/auth/verify-email') { return $auth->verify($body); }
        if ($method === 'POST' && $path === '/auth/resend-verification') { return $auth->resend(); }
        if ($method === 'GET' && $path === '/products') { return $catalog->products($_GET); }
        if ($method === 'GET' && preg_match('~^/products/([1-9][0-9]*)$~', $path, $match)) { return $catalog->product((int) $match[1]); }
        if ($method === 'GET' && $path === '/categories') { return $catalog->categories(); }
        if ($method === 'GET' && $path === '/stores') { return $catalog->stores(); }
        if ($method === 'GET' && $path === '/locations/provinces') { return (new LocationDirectory())->areas(); }
        if ($method === 'GET' && $path === '/locations/cities') { return (new LocationDirectory())->cities(Input::text($_GET, 'province_code', 10, 10)); }
        if ($method === 'GET' && $path === '/locations/barangays') { return (new LocationDirectory())->barangays(Input::text($_GET, 'city_code', 10, 10)); }
        if ($method === 'GET' && preg_match('~^/stores/([1-9][0-9]*)$~', $path, $match)) { return $catalog->store((int) $match[1]); }

        $user = $auth->customer();
        $id = (int) $user['user_id'];
        if ($method==='POST' && $path==='/auth/password') return $auth->changePassword($body);
        if ($method==='GET' && $path==='/preferences') return $module->notifications->preferences($id);
        if ($method==='PATCH' && $path==='/preferences') return $module->notifications->savePreferences($id,$body);
        if ($method==='GET' && $path==='/saved-products') return $module->saved->list($id);
        if (preg_match('~^/saved-products/([1-9][0-9]*)$~',$path,$m)) {
            if ($method==='PUT') return $module->saved->save($id,(int)$m[1]);
            if ($method==='DELETE') return $module->saved->remove($id,(int)$m[1]);
        }
        if ($method==='GET' && $path==='/vouchers') return $module->vouchers->list($id);
        if ($method==='POST' && preg_match('~^/vouchers/([1-9][0-9]*)/claim$~',$path,$m)) return $module->vouchers->claim($id,(int)$m[1]);
        if ($method==='GET' && $path==='/conversations') return $module->messages->threads($id);
        if ($method==='POST' && $path==='/conversations') return $module->messages->open($id,Input::integer($body,'seller_id',1,PHP_INT_MAX));
        if (preg_match('~^/conversations/([1-9][0-9]*)(?:/(messages|read))?$~',$path,$m)) {
            if ($method==='GET' && empty($m[2])) return $module->messages->thread($id,(int)$m[1],$_GET);
            if ($method==='POST' && ($m[2]??'')==='messages') return $module->messages->send($id,(int)$m[1],$body);
            if ($method==='PATCH' && ($m[2]??'')==='read') return $module->messages->read($id,(int)$m[1]);
        }
        if ($method==='POST' && $path==='/uploads') return $module->uploads->save($id,$body);
        if ($method==='GET' && $path==='/returns') return $module->returns->list($id);
        if ($method==='POST' && $path==='/returns') return $module->returns->submit($id,$body);
        if ($method==='POST' && preg_match('~^/returns/([1-9][0-9]*)/appeal$~',$path,$m)) return $module->returns->appeal($id,(int)$m[1],$body);
        if ($method==='GET' && $path==='/seller-applications') return $module->applications->list($id);
        if ($method==='POST' && $path==='/seller-applications') return $module->applications->submit($id,$body);
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
            $variantId = isset($body['variant_id']) ? Input::text($body, 'variant_id', 100) : 'std';
            if ($method === 'PUT') { return $cart->set($id, (int) $match[1], Input::integer($body, 'quantity'), $variantId); }
            if ($method === 'DELETE') { return $cart->remove($id, (int) $match[1], $variantId); }
        }
        if ($method === 'POST' && $path === '/checkout/quote') {
            if (isset($body['items']) && !is_array($body['items'])) { throw new ApiError(422, 'Invalid cart selection.'); }
            if (isset($body['voucher_ids']) && !is_array($body['voucher_ids'])) throw new ApiError(422,'Invalid voucher selection.');
            return $cart->quote($id, $body['items'] ?? null,$body['voucher_ids']??[]);
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
