<?php
declare(strict_types=1);

use SaleSync\Core\{ApiError, Database, Input, Session};
use SaleSync\Customer\Module;
use SaleSync\Http\Api;
use SaleSync\Payments\{Gateway, Webhook};

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/app/bootstrap.php';

$path = dirname(__DIR__) . '/storage/tests-' . bin2hex(random_bytes(6)) . '/test.sqlite';
$db = new Database($path);
$db->pdo->exec(file_get_contents(dirname(__DIR__) . '/database/migrations/001_customer.sql'));
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function fails(int $status, callable $work, string $message): void
{
    try { $work(); } catch (ApiError $error) { check($error->status === $status, $message . ' (wrong status)'); return; }
    throw new RuntimeException($message . ' (no error)');
}

final class FakeGateway implements Gateway
{
    public int $creates = 0;
    public bool $fail = false;
    public array $session = [];

    public function ready(): bool { return true; }

    public function create(array $order): array
    {
        $this->creates++;
        if ($this->fail) { throw new ApiError(502, 'Simulated provider timeout'); }
        return $this->session = ['id' => 'cs_test' . $order['order_id'], 'attributes' => ['livemode' => false, 'reference_number' => $order['order_number'], 'checkout_url' => 'https://checkout.paymongo.com/test', 'status' => 'active', 'payments' => []]];
    }
    public function retrieve(string $reference): array { return $this->session; }
}

try {
    check(Input::password(['password' => '  preserve spaces  ']) === '  preserve spaces  ', 'Preserve password whitespace');
    fails(422, fn() => Input::password(['password' => str_repeat('é', 40)]), 'Reject passwords beyond bcrypt byte limit');
    foreach ([['Customer A', 'a@example.test', 'customer'], ['Customer B', 'b@example.test', 'customer'], ['Seller', 's@example.test', 'seller']] as $user) {
        $db->run("INSERT INTO users(name,email,role,password_hash,email_verified_at) VALUES (?,?,?,'unused','2026-01-01T00:00:00Z')", $user);
    }
    $db->run("INSERT INTO seller_profiles(user_id,store_name,approval_status) VALUES (3,'Test Store','approved')");
    $db->run("INSERT INTO categories(category_name) VALUES ('Test')");
    $db->run("INSERT INTO products(seller_id,category_id,sku,name,price,stock_qty,status) VALUES (1,1,'TEST','Original Product',15000,10,'published')");
    $moduleConfig = array_replace($config, ['database' => $path, 'shipping_fee' => 5000, 'session_cookie_path' => '/shared-app']);
    $gateway = new FakeGateway();
    $customer = new Module($moduleConfig, $db, $gateway);
    $cart = $customer->cart;
    $orders = $customer->orders;
    $profiles = $customer->profile;
    $catalog = $customer->catalog;
    $input = ['label' => 'Home', 'recipient_name' => 'Customer A', 'phone' => '09123456789', 'address_text' => '123 Test Street, Test City', 'is_default' => true];
    $a = $profiles->saveAddress(1, null, $input);
    $b = $profiles->saveAddress(2, null, $input);
    fails(404, fn() => $profiles->saveAddress(2, $a['address_id'], $input), 'Prevent cross-customer address updates');
    fails(404, fn() => $orders->checkout(1, ['address_id' => $b['address_id']], 'address-isolation-001'), 'Prevent checkout with another customer address');
    check(count($catalog->products([])['items']) === 1, 'Published catalogue');
    $db->run("UPDATE seller_profiles SET approval_status='pending'");
    check($catalog->products([])['total'] === 0, 'Hide unapproved store products');
    fails(404, fn() => $catalog->product(1), 'Hide unpublished public detail');
    $db->run("UPDATE seller_profiles SET approval_status='approved'");
    fails(409, fn() => $cart->set(1, 1, 11), 'Reject oversubscribed cart');
    $cart->set(1, 1, 2);
    $db->run('UPDATE products SET price=16000');
    check($cart->get(1)['total'] === 37000, 'Recalculate current prices and shipping');
    $order = $orders->checkout(1, ['address_id' => $a['address_id'], 'total' => 1], 'checkout-request-0001');
    check($order['total'] === 37000, 'Ignore client total');
    check($db->one('SELECT stock_qty FROM products')['stock_qty'] === 8, 'Reserve stock');
    check($cart->get(1)['items'] === [], 'Clear checked-out cart');
    $repeat = $orders->checkout(1, ['address_id' => $a['address_id']], 'checkout-request-0001');
    check($repeat['order_id'] === $order['order_id'] && $db->one('SELECT stock_qty FROM products')['stock_qty'] === 8, 'Checkout retry does not reserve twice');
    fails(404, fn() => $orders->get(2, $order['order_id']), 'Prevent cross-customer order reads');
    fails(404, fn() => $orders->cancel(2, $order['order_id']), 'Prevent cross-customer cancellations');
    $profiles->deleteAddress(1, $a['address_id']);
    check($orders->get(1, $order['order_id'])['address']['address_text'] === $input['address_text'], 'Keep immutable order delivery snapshot');
    $db->run("UPDATE products SET name='Changed',price=99999");
    check($orders->get(1, $order['order_id'])['items'][0]['unit_price'] === 16000, 'Keep immutable order price');
    $payments = $customer->payments;
    fails(404, fn() => $payments->start(2, $order['order_id']), 'Prevent cross-customer payment initiation');
    $payments->start(1, $order['order_id']);
    $payments->start(1, $order['order_id']);
    check($gateway->creates === 1, 'Reuse existing payment session');
    fails(409, fn() => $orders->cancel(1, $order['order_id']), 'Do not release stock while provider session active');
    $session = $gateway->session;
    $session['attributes']['payments'] = [['attributes' => ['amount' => 1, 'currency' => 'PHP', 'status' => 'paid']]];
    fails(422, fn() => $payments->apply($session, 'bad-amount'), 'Reject mismatched payment amount');
    check($orders->get(1, $order['order_id'])['status'] === 'pending_payment', 'Rejecting payment preserves pending order');
    $session['attributes']['payments'][0]['attributes']['amount'] = 37000;
    $live = $session;
    $live['attributes']['livemode'] = true;
    fails(422, fn() => $payments->apply($live), 'Reject live payment');
    $wrong = $session;
    $wrong['id'] = 'cs_wrong';
    fails(409, fn() => $payments->apply($wrong), 'Reject payment reference mismatch');
    $payments->apply($session, 'paid-event-1');
    $payments->apply($session, 'paid-event-1');
    $payments->apply($session, 'paid-event-2');
    $paid = $orders->get(1, $order['order_id']);
    check($paid['status'] === 'processing' && $paid['payment']['status'] === 'paid', 'Verified payment advances order');
    check(count($paid['history']) === 2, 'Repeated payment events do not duplicate history');
    check($db->one("SELECT COUNT(*) AS count FROM notifications WHERE title='Payment confirmed'")['count'] === 1, 'Repeated payment events do not duplicate notifications');
    $expired = $gateway->session;
    $expired['attributes']['status'] = 'expired';
    $payments->apply($expired);
    check($orders->get(1, $order['order_id'])['payment']['status'] === 'paid', 'Stale expiry cannot regress paid order');
    $notice = $profiles->notifications(1, [])['items'][0]['notification_id'];
    fails(404, fn() => $profiles->readNotification(2, $notice), 'Prevent cross-customer notification changes');
    $profiles->readNotification(1, $notice);
    check($profiles->notifications(1, [])['unread'] === 1, 'Mark own notification read');

    $a = $profiles->saveAddress(1, null, $input);
    $cart->set(1, 1, 2);
    $stockBeforeFailure = $db->one('SELECT stock_qty FROM products')['stock_qty'];
    $ordersBeforeFailure = $db->one('SELECT COUNT(*) AS count FROM orders')['count'];
    $db->pdo->exec("CREATE TRIGGER simulate_write_failure BEFORE INSERT ON order_items BEGIN SELECT RAISE(ABORT, 'simulated disk/write failure'); END;");
    try {
        $orders->checkout(1, ['address_id' => $a['address_id']], 'rollback-request-001');
        throw new RuntimeException('Expected a simulated checkout write failure');
    } catch (PDOException $error) {
        check($db->one('SELECT stock_qty FROM products')['stock_qty'] === $stockBeforeFailure, 'Rollback reserved stock on mid-checkout write failure');
        check($db->one('SELECT COUNT(*) AS count FROM orders')['count'] === $ordersBeforeFailure && count($cart->get(1)['items']) === 1, 'Rollback partial order and preserve cart on write failure');
    }
    $db->pdo->exec('DROP TRIGGER simulate_write_failure');
    $db->run('UPDATE products SET stock_qty=1');
    $before = $db->one('SELECT COUNT(*) AS count FROM orders')['count'];
    fails(409, fn() => $orders->checkout(1, ['address_id' => $a['address_id']], 'stock-race-request-01'), 'Reject stock changed after cart addition');
    check($db->one('SELECT COUNT(*) AS count FROM orders')['count'] === $before && count($cart->get(1)['items']) === 1, 'Stock failure preserves cart and creates no order');
    $db->run('UPDATE products SET stock_qty=10');
    $cancel = $orders->checkout(1, ['address_id' => $a['address_id']], 'cancel-request-0001');
    $orders->cancel(1, $cancel['order_id']);
    $orders->cancel(1, $cancel['order_id']);
    check($db->one('SELECT stock_qty FROM products')['stock_qty'] === 10, 'Cancellation releases reservation exactly once');
    $cart->set(1, 1, 1);
    $expireOrder = $orders->checkout(1, ['address_id' => $a['address_id']], 'expiry-request-0001');
    $payments->start(1, $expireOrder['order_id']);
    $gateway->session['attributes']['status'] = 'expired';
    $payments->refresh(1, $expireOrder['order_id']);
    $payments->refresh(1, $expireOrder['order_id']);
    check($orders->get(1, $expireOrder['order_id'])['status'] === 'cancelled' && $db->one('SELECT stock_qty FROM products')['stock_qty'] === 10, 'Provider expiry releases reservation exactly once');

    $cart->set(1, 1, 1);
    $unknown = $orders->checkout(1, ['address_id' => $a['address_id']], 'timeout-request-0001');
    $gateway->fail = true;
    fails(502, fn() => $payments->start(1, $unknown['order_id']), 'Report provider timeout');
    $attempts = $gateway->creates;
    fails(409, fn() => $payments->start(1, $unknown['order_id']), 'Block retry after uncertain provider response');
    check($gateway->creates === $attempts, 'No duplicate external request on timeout');
    fails(409, fn() => $orders->cancel(1, $unknown['order_id']), 'Do not cancel uncertain payment');
    $gateway->fail = false;
    $recovered = $gateway->create($unknown);
    $payments->apply($recovered);
    check($orders->get(1, $unknown['order_id'])['payment']['status'] === 'pending', 'Recover uncertain creation from verified provider session');

    $payload = json_encode(['data' => ['id' => 'event-test', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => $session]]], JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = 't=' . $timestamp . ',te=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'test-secret') . ',li=';
    check(Webhook::parse($payload, $signature, 'test-secret')['session']['id'] === $session['id'], 'Accept correctly signed webhook');
    fails(401, fn() => Webhook::parse($payload . ' ', $signature, 'test-secret'), 'Reject tampered webhook body');
    $old = (string) (time() - 600);
    fails(401, fn() => Webhook::parse($payload, 't=' . $old . ',te=' . hash_hmac('sha256', $old . '.' . $payload, 'test-secret'), 'test-secret'), 'Reject stale webhook');
    fails(503, fn() => Webhook::parse($payload, $signature, ''), 'Reject unconfigured webhook');
    // Simulate the main application starting and authenticating a shared session
    // before routing a request into the injected customer module.
    Session::start($moduleConfig);
    Session::login(1);
    $hostSessionId = session_id();
    $hostCsrf = $_SESSION['csrf'];
    $hostApi = new Api($moduleConfig, $customer);
    check($hostApi->dispatch('GET', '/profile', '')['user_id'] === 1, 'Mounted customer API accepts shared host identity');
    check(session_id() === $hostSessionId && $_SESSION['csrf'] === $hostCsrf, 'Customer dispatcher preserves active host session');
    check(session_name() === 'salesync' && session_get_cookie_params()['path'] === '/shared-app', 'Shared session uses common name and configured cookie path');
    check($hostApi->dispatch('GET', '/session', '')['payment_configured'] === true, 'Mounted API uses injected payment gateway capability');
    session_write_close();
    echo "PASS: $checks service, module integration and payment checks.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
}
