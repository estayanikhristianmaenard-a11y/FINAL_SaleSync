import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'E:/xampp/php/php.exe' : 'php');
const directory = path.join(root, 'storage', `http-tests-${Date.now()}`);
await mkdir(directory, { recursive: true });
const socket = createServer();
await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const base = `http://127.0.0.1:${port}`;
const env = { ...process.env, SALESYNC_DB: path.join(directory, 'test.sqlite'), SALESYNC_URL: base };
function cli(...args) {
    const result = spawnSync(php, args, { cwd: root, env, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
cli('scripts/migrate.php');
cli('scripts/seed.php');
const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public', 'scripts/serve.php'], { cwd: root, env, stdio: ['ignore', 'ignore', 'pipe'] });
let serverLog = '';
server.stderr.on('data', data => { serverLog += data; });
let checks = 0;
function check(condition, description) { assert.ok(condition, description); checks++; }

class Client {
    cookie = '';
    csrf = '';
    async request(route, { method = 'GET', body, status = 200, csrf = true, key, raw } = {}) {
        const headers = { Cookie: this.cookie };
        if (csrf && method !== 'GET') headers['X-CSRF-Token'] = this.csrf;
        if (body !== undefined || raw !== undefined) headers['Content-Type'] = 'application/json';
        if (key) headers['Idempotency-Key'] = key;
        const response = await fetch(`${base}/api/index.php?route=${encodeURIComponent(route)}`, {
            method, headers, body: raw ?? (body === undefined ? undefined : JSON.stringify(body)),
        });
        const cookie = response.headers.get('set-cookie');
        if (cookie) this.cookie = cookie.split(';')[0];
        const result = await response.json();
        check(response.status === status, `${method} ${route}: expected ${status}, received ${response.status} ${JSON.stringify(result)}`);
        if (result.data?.csrf_token) this.csrf = result.data.csrf_token;
        return result.data ?? result.error;
    }
}

try {
    let ready = false;
    for (let attempt = 0; attempt < 80; attempt++) {
        try { await fetch(base); ready = true; break; } catch { await new Promise(resolve => setTimeout(resolve, 50)); }
    }
    assert.ok(ready, serverLog);
    const page = await (await fetch(base)).text();
    check(page.includes('id="app"') && page.includes('customer.js'), 'One page frontend mount is served');
    check((await fetch(`${base}/app/bootstrap.php`)).status === 404, 'Private backend files are outside the public root');
    const customer = new Client();
    const initial = await customer.request('/session');
    check(initial.user === null && Boolean(initial.csrf_token), 'Guest session and CSRF token');
    const products = await customer.request('/products');
    check(products.total === 12, 'Public catalogue fixtures');
    await customer.request('/products', { status: 200 });
    await customer.request('/cart', { status: 401 });
    await customer.request('/orders', { status: 401 });
    await customer.request('/auth/register', { method: 'POST', body: {}, csrf: false, status: 419 });
    await customer.request('/auth/register', { method: 'POST', raw: '{broken', status: 400 });
    const password = 'CustomerTestPass123!';
    const user = await customer.request('/auth/register', { method: 'POST', body: { name: 'HTTP Customer', email: 'http@example.test', password, role: 'admin_management', email_verified_at: '2026-01-01' } });
    check(user.user.role === 'customer' && user.user.email_verified_at === null, 'Registration ignores role/verification injection');
    check(user.csrf_token !== initial.csrf_token, 'Registration rotates session token');
    const address = await customer.request('/addresses', { method: 'POST', body: { label: 'Home', recipient_name: 'Customer', phone: '09123456789', address_text: '123 Test Street, Test City', is_default: true } });
    const otherAddress = await customer.request('/addresses', { method: 'POST', body: { label: 'Work', recipient_name: 'Customer', phone: '09123456789', address_text: '456 Work Street, Test City', is_default: true } });
    const addresses = await customer.request('/addresses');
    check(addresses.filter(item => item.is_default).length === 1 && addresses[0].address_id === otherAddress.address_id, 'Exactly one default saved address');
    await customer.request(`/addresses/${otherAddress.address_id}`, { method: 'DELETE' });
    const newDefault = await customer.request('/addresses');
    check(newDefault[0].is_default === 1, 'Deleting default selects remaining address');
    await customer.request('/cart/items/1', { method: 'PUT', body: { quantity: -1 }, status: 422 });
    await customer.request('/cart/items/1', { method: 'PUT', body: { quantity: 21 }, status: 409 });
    const cart = await customer.request('/cart/items/1', { method: 'PUT', body: { quantity: 2 } });
    check(cart.total === 20000, 'Cart uses server price');
    await customer.request('/checkout', { method: 'POST', body: { address_id: address.address_id }, key: 'http-checkout-key-0001', status: 403 });
    const mails = await readdir(path.join(directory, 'mail'));
    const mail = JSON.parse(await readFile(path.join(directory, 'mail', mails[0]), 'utf8'));
    const token = mail.body.match(/#verify-email=([a-f0-9]+)/)[1];
    await customer.request('/auth/verify-email', { method: 'POST', body: { token } });
    await customer.request('/auth/verify-email', { method: 'POST', body: { token }, status: 422 });
    await customer.request('/checkout', { method: 'POST', body: { address_id: address.address_id }, status: 422 });
    const order = await customer.request('/checkout', { method: 'POST', body: { address_id: address.address_id, total: 1 }, key: 'http-checkout-key-0001' });
    check(order.total === 20000 && order.status === 'pending_payment', 'Verified checkout ignores forged total');
    const retry = await customer.request('/checkout', { method: 'POST', body: { address_id: address.address_id }, key: 'http-checkout-key-0001' });
    check(retry.order_id === order.order_id, 'HTTP checkout idempotency');
    await customer.request(`/orders/${order.order_id}/payment`, { method: 'POST', status: 503 });
    const other = new Client();
    await other.request('/session');
    await other.request('/auth/register', { method: 'POST', body: { name: 'Other Customer', email: 'other@example.test', password } });
    await other.request(`/orders/${order.order_id}`, { status: 404 });
    await other.request(`/addresses/${address.address_id}`, { method: 'DELETE', status: 404 });
    const notification = (await customer.request('/notifications')).items[0];
    await other.request(`/notifications/${notification.notification_id}/read`, { method: 'PATCH', status: 404 });
    await customer.request(`/notifications/${notification.notification_id}/read`, { method: 'PATCH' });
    await customer.request('/profile', { method: 'PATCH', body: { name: 'Updated Customer', role: 'seller' } });
    check((await customer.request('/profile')).role === 'customer', 'Profile cannot elevate role');
    await customer.request(`/orders/${order.order_id}/cancel`, { method: 'POST' });
    check((await customer.request('/products/1')).stock_qty === 20, 'HTTP cancellation restores stock');
    await customer.request('/auth/logout', { method: 'POST' });
    await customer.request('/profile', { status: 401 });
    await customer.request('/auth/login', { method: 'POST', body: { email: 'http@example.test', password: 'wrong-password' }, status: 401 });
    await customer.request('/auth/login', { method: 'POST', body: { email: 'http@example.test', password } });
    check((await customer.request('/profile')).name === 'Updated Customer', 'Login restores persisted profile');
    await customer.request('/seller/products', { method: 'POST', body: {}, status: 404 });
    const list = await fetch(`${base}/api/products?q=Canvas&sort=price_asc`);
    const listBody = await list.json();
    check(list.status === 200 && listBody.data.total === 1, 'Clean API routing and query search');

    // Change only isolated fixture data to verify server-side role/status checks on every request.
    const fixture = path.join(directory, 'change-user.php');
    await writeFile(fixture, `<?php
$config = require ${JSON.stringify(path.join(root, 'app/bootstrap.php')).replaceAll('\\\\', '/')};
$db = new SaleSync\\Core\\Database($config['database']);
$db->run("UPDATE users SET role=? WHERE email='http@example.test'", [$argv[1]]);
if (($argv[2] ?? '') === 'suspended') $db->run("UPDATE users SET status='suspended' WHERE email='http@example.test'");
`);
    for (const role of ['seller', 'admin_maintenance', 'admin_management']) {
        cli(fixture, role);
        await customer.request('/profile', { status: 403 });
        await customer.request('/cart/items/1', { method: 'PUT', body: { quantity: 1 }, status: 403 });
    }
    cli(fixture, 'customer', 'suspended');
    await customer.request('/profile', { status: 403 });
    for (let attempt = 0; attempt < 10; attempt++) {
        await other.request('/auth/login', { method: 'POST', body: { email: 'nobody@example.test', password: 'bad-password' }, status: 401 });
    }
    await other.request('/auth/login', { method: 'POST', body: { email: 'nobody@example.test', password: 'bad-password' }, status: 429 });
    const errors = await readFile(path.join(directory, 'app.log'), 'utf8').catch(() => '');
    check(errors === '', `No application warnings or exceptions: ${errors}`);
    console.log(`PASS: ${checks} HTTP/session/customer checks.`);
} finally {
    server.kill();
    await new Promise(resolve => server.once('exit', resolve));
}
