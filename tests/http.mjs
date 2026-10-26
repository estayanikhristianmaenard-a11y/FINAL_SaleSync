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
    async request(route, { method = 'GET', body, status = 200, csrf = true, key, raw, query = {} } = {}) {
        const headers = { Cookie: this.cookie };
        if (csrf && method !== 'GET') headers['X-CSRF-Token'] = this.csrf;
        if (body !== undefined || raw !== undefined) headers['Content-Type'] = 'application/json';
        if (key) headers['Idempotency-Key'] = key;
        const response = await fetch(`${base}/api/index.php?route=${encodeURIComponent(route)}&${new URLSearchParams(query)}`, {
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
    const locationAreas = await customer.request('/locations/provinces');
    check(locationAreas.items.length===100 && locationAreas.source.counts.barangays===42010, 'Complete local Philippine province/region and barangay snapshot');
    const ncrCities = await customer.request('/locations/cities', { query:{province_code:'1300000000'} });
    check(ncrCities.length===17 && ncrCities.some(city=>city.code==='1381200000') && ncrCities.some(city=>city.name==='Pateros'), 'Metro Manila includes cities and Pateros without requiring a province');
    const pasigBarangays = await customer.request('/locations/barangays', { query:{city_code:'1381200000'} });
    check(pasigBarangays.some(barangay=>barangay.name==='Manggahan'), 'Pasig barangays include Manggahan');
    const registrationDetails = { phone:'+63 917 123 4567', birth_date:'2000-05-14', house_number:'123', street:'Test Street', barangay:'1381200019', city:'1381200000', province:'1300000000', postal_code:'1605' };
    const registration = { name:'HTTP Customer', email:'http@example.test', password, ...registrationDetails };
    await customer.request('/auth/register', { method:'POST', body:{...registration,province:'0402100000'},status:422 });
    await customer.request('/auth/register', { method:'POST', body:{...registration,city:'1381500000'},status:422 });
    await customer.request('/auth/register', { method:'POST', body:{...registration,barangay:'0000000000'},status:422 });
    const underage = `${Number(initial.registration_date.slice(0,4))-17}${initial.registration_date.slice(4)}`;
    const ageError = await customer.request('/auth/register', { method:'POST', body:{ ...registration, birth_date:underage, age:99, eligible:true }, status:422 });
    check(Boolean(ageError.fields.birth_date), 'Server rejects underage birthday despite fabricated age/eligibility');
    await customer.request('/auth/register', { method:'POST', body:{ ...registration, birth_date:'2000-02-30' }, status:422 });
    await customer.request('/auth/register', { method:'POST', body:{ ...registration, birth_date:'2999-01-01' }, status:422 });
    await customer.request('/auth/register', { method:'POST', body:{ ...registration, phone:'not-a-phone' }, status:422 });
    await customer.request('/auth/register', { method:'POST', body:{ ...registration, street:'' }, status:422 });
    const user = await customer.request('/auth/register', { method: 'POST', body: { ...registration, role: 'admin_management', email_verified_at: '2026-01-01' } });
    check(user.user.role === 'customer' && user.user.email_verified_at === null, 'Registration ignores role/verification injection');
    check(user.user.birth_date==='2000-05-14' && user.user.phone==='+639171234567', 'Birthday and normalized phone saved on customer profile');
    const registrationAddresses = await customer.request('/addresses');
    check(registrationAddresses.length===1 && registrationAddresses[0].is_default===1 && registrationAddresses[0].label==='Home', 'Registration creates one default Home address');
    check(registrationAddresses[0].recipient_name==='HTTP Customer' && registrationAddresses[0].phone==='+639171234567' && registrationAddresses[0].address_text==='123 Test Street, San Antonio, City of Pasig, Metro Manila (NCR) 1605', 'Canonical registration location names saved under My Addresses');
    check(user.csrf_token !== initial.csrf_token, 'Registration rotates session token');
    const addressInput = {...registrationDetails,label:'Home',recipient_name:'Customer',phone:'09123456789',is_default:true};
    await customer.request('/addresses', { method:'POST',body:{...addressInput,province:'0402100000'},status:422 });
    await customer.request('/addresses', { method:'POST',body:{label:'Invalid',recipient_name:'Customer',phone:'09123456789',address_text:'Fabricated free-text address'},status:422 });
    const address = await customer.request('/addresses', { method: 'POST', body: {...addressInput,address_text:'Ignored fabricated address'} });
    check(address.province_code==='1300000000'&&address.city_code==='1381200000'&&address.barangay_code==='1381200019'&&address.house_number==='123','New addresses persist structured location fields');
    check(address.address_text.includes('San Antonio, City of Pasig, Metro Manila (NCR)'),'Server composes address instead of trusting submitted free text');
    await customer.request(`/addresses/${address.address_id}`, { method:'PATCH',body:{...addressInput,city:'1381500000'},status:422 });
    const otherAddress = await customer.request('/addresses', { method: 'POST', body: {...addressInput,label:'Work',house_number:'456',street:'Work Street'} });
    const addresses = await customer.request('/addresses');
    check(addresses.filter(item => item.is_default).length === 1 && addresses[0].address_id === otherAddress.address_id, 'Exactly one default saved address');
    await customer.request(`/addresses/${otherAddress.address_id}`, { method: 'DELETE' });
    const newDefault = await customer.request('/addresses');
    check(newDefault[0].is_default === 1, 'Deleting default selects remaining address');
    const defaultUpdate = await customer.request(`/addresses/${address.address_id}`,{method:'PATCH',body:{is_default:true}});
    check(defaultUpdate.province_code==='1300000000'&&defaultUpdate.street==='Test Street','Default-only update preserves structured address fields');
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
    await other.request('/auth/register', { method: 'POST', body: { name: 'Other Customer', email: 'other@example.test', password, ...registrationDetails } });
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
    await customer.request('/saved-products/1',{method:'PUT'});
    check((await customer.request('/saved-products')).length===1&&(await other.request('/saved-products')).length===0,'HTTP saved-product ownership');
    await customer.request('/saved-products/1',{method:'DELETE'});
    await customer.request('/preferences',{method:'PATCH',body:{orders:false,messages:false,promotions:true}});
    check((await customer.request('/preferences')).orders===false,'HTTP notification preferences saved');
    const offers=await customer.request('/vouchers');check(Array.isArray(offers),'Voucher list uses shared database');
    await customer.request('/vouchers/99999/claim',{method:'POST',status:404});
    const store=(await customer.request('/stores'))[0];
    const thread=await customer.request('/conversations',{method:'POST',body:{seller_id:store.seller_id}});
    await customer.request(`/conversations/${thread.thread_id}/messages`,{method:'POST',body:{body:'Question from HTTP customer',client_key:'http-message-000001'}});
    check((await customer.request(`/conversations/${thread.thread_id}`)).messages.length===1,'HTTP message persisted');
    await other.request(`/conversations/${thread.thread_id}`,{status:404});
    await other.request(`/conversations/${thread.thread_id}/read`,{method:'PATCH',status:404});
    await customer.request(`/conversations/${thread.thread_id}/read`,{method:'PATCH'});
    const image=await customer.request('/uploads',{method:'POST',body:{purpose:'return',name:'photo.png',content:'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1EAAAAASUVORK5CYII='}});
    const binary=await fetch(`${base}/api/attachment.php?id=${image.upload_id}`,{headers:{Cookie:customer.cookie}});
    check(binary.status===200&&binary.headers.get('content-type')==='image/png','Authenticated binary attachment route');
    check((await fetch(`${base}/api/attachment.php?id=${image.upload_id}`,{headers:{Cookie:other.cookie}})).status===404,'Attachment denies another customer');
    check((await fetch(`${base}/api/attachment.php?id=${image.upload_id}`)).status===401,'Attachment denies guests');
    await customer.request('/returns',{method:'POST',body:{order_item_id:order.items[0].order_item_id,quantity:1,reason:'Damaged item',explanation:'Damaged product on delivery',solution:'refund_only',client_key:'http-return-000001'},status:409});
    check((await customer.request('/returns')).length===0,'Rejected return attempt creates no case');
    const application=await customer.request('/seller-applications',{method:'POST',body:{store_name:'HTTP Store',business_name:'HTTP Business',business_type:'Sole proprietor',contact_name:'HTTP Customer',contact_email:'http@example.test',phone:'09123456789',address_text:'123 Street',description:'Business description',status:'approved',role:'seller'}});
    check(application.status==='pending'&&(await customer.request('/profile')).role==='customer','Application cannot self-approve or grant role');
    check((await other.request('/seller-applications')).length===0,'Application status isolated');
    const stale=new Client();await stale.request('/session');await stale.request('/auth/login',{method:'POST',body:{email:'http@example.test',password}});
    await customer.request('/auth/password',{method:'POST',csrf:false,body:{},status:419});
    await customer.request('/auth/password',{method:'POST',body:{current_password:'wrong',new_password:'ChangedCustomer123!',confirm_password:'ChangedCustomer123!'},status:422});
    await customer.request('/auth/password',{method:'POST',body:{current_password:password,new_password:'ChangedCustomer123!',confirm_password:'ChangedCustomer123!'}});
    await stale.request('/profile',{status:401});
    check((await customer.request('/profile')).role==='customer','Password change keeps current session and revokes older session');
    check(cli('scripts/send-notifications.php').includes('Driver: log'),'Notification worker uses development mail logging');
    check(cli('scripts/send-notifications.php').includes('Processed 0'),'Completed notification outbox rows are not sent twice');
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
