// Requires a headless Chromium/Edge debugging endpoint (default http://127.0.0.1:9223).
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const directory = path.join(root, 'storage', `customer-ui-tests-${Date.now()}`);
await fs.mkdir(directory, { recursive:true });
const socket = createServer(); await new Promise(resolve => socket.listen(0,'127.0.0.1',resolve));
const port = socket.address().port; await new Promise(resolve => socket.close(resolve));
const base = `http://127.0.0.1:${port}`;
const env = { ...process.env, SALESYNC_DB:path.join(directory,'test.sqlite'), SALESYNC_URL:base };
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'E:/xampp/php/php.exe' : 'php');
for (const script of ['scripts/migrate.php','scripts/seed-ui-catalog.php']) {
    const result = spawnSync(php,[script],{cwd:root,env,encoding:'utf8'});
    assert.equal(result.status,0,result.stderr || result.stdout);
}
const server = spawn(php,['-S',`127.0.0.1:${port}`,'-t','public','scripts/serve.php'],{cwd:root,env,stdio:'ignore'});
const debug = process.env.CDP_URL || 'http://127.0.0.1:9223';
let ws;
let target;
let sequence = 0;
const pending = new Map(), exceptions = [], failedResources = [];
let checks = 0;
let adversarialFindings = [];
const check = (value,message) => { assert.ok(value,message); checks++; };
function fixture(code) {
    const result=spawnSync(php,['-r',`$config=require 'app/bootstrap.php';$db=new SaleSync\\Core\\Database($config['database']);${code}`],{cwd:root,env,encoding:'utf8'});
    assert.equal(result.status,0,result.stderr||result.stdout);
    return result.stdout;
}
function send(method,params={}) {
    return new Promise((resolve,reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); },20000);
        pending.set(id, result => { clearTimeout(timer); result.error ? reject(new Error(JSON.stringify(result.error))) : resolve(result.result); });
        ws.send(JSON.stringify({id,method,params}));
    });
}
async function evaluate(expression) {
    const result = await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
}
async function wait(expression, label=expression) {
    for (let attempt=0;attempt<100;attempt++) {
        if (await evaluate(expression)) return;
        await new Promise(resolve=>setTimeout(resolve,80));
    }
    throw new Error(`Timed out waiting for ${label}: `+await evaluate('document.body.innerText.slice(-1200)'));
}
async function click(selector) {
    check(await evaluate(`!!document.querySelector(${JSON.stringify(selector)})`),`Control exists: ${selector}`);
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    await new Promise(resolve=>setTimeout(resolve,100));
}
async function route(value) {
    await evaluate(`go(${JSON.stringify(value)})`);
    await wait(`route()===${JSON.stringify(value)}&&view.renderedRoute===${JSON.stringify(value)}&&!view.busy`);
    await new Promise(resolve=>setTimeout(resolve,100));
}
async function reload() {
    await evaluate('window.__beforeReload=true');
    await send('Page.reload');
    await wait('!window.__beforeReload&&window.SaleSync?.ready&&view.renderedRoute===route()');
}
async function form(id,data) {
    if (data.province) {
        await wait(`Boolean(document.querySelector('#${id} [name="province"] option[value="${data.province}"]'))`);
        await evaluate(`(()=>{const s=document.querySelector('#${id} [name="province"]');s.value=${JSON.stringify(data.province)};s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
        await wait(`!document.querySelector('#${id} [name="city"]').disabled&&Boolean(document.querySelector('#${id} [name="city"] option[value="${data.city}"]'))`);
        await evaluate(`(()=>{const s=document.querySelector('#${id} [name="city"]');s.value=${JSON.stringify(data.city)};s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
        await wait(`!document.querySelector('#${id} [name="barangay"]').disabled&&Boolean(document.querySelector('#${id} [name="barangay"] option[value="${data.barangay}"]'))`);
    }
    await evaluate(`(()=>{const f=document.getElementById(${JSON.stringify(id)});for(const [name,value]of Object.entries(${JSON.stringify(data)})){f.elements[name].value=value}f.requestSubmit()})()`);
    if (id==='auth-form'&&data.province&&await evaluate('document.querySelector("#auth-form")?.dataset.registrationStep==="2"')) {
        await evaluate('document.getElementById("auth-form").requestSubmit()');
    }
}
try {
    for(let attempt=0;attempt<80;attempt++) { try { await fetch(base); break; } catch { await new Promise(resolve=>setTimeout(resolve,60)); } }
    target = await (await fetch(`${debug}/json/new?${encodeURIComponent('about:blank')}`,{method:'PUT'})).json();
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(resolve=>ws.addEventListener('open',resolve,{once:true}));
    ws.onmessage = event => {
        const result=JSON.parse(event.data);
        if(result.id){pending.get(result.id)?.(result);pending.delete(result.id);}
        if(result.method==='Runtime.exceptionThrown') exceptions.push(result.params.exceptionDetails.exception?.description || result.params.exceptionDetails.text);
        if(result.method==='Network.responseReceived'&&result.params.response.status>=400&&!result.params.response.url.includes('/api/')) failedResources.push(result.params.response.url);
    };
    await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await send('Page.navigate',{url:base});
    await wait('window.SaleSync?.ready');
    check(await evaluate('S.products.length===141'),'141 coworker catalogue products loaded from backend');
    check(await evaluate('S.users.length===0&&S.orders.length===0'),'No prototype accounts or historical fake orders imported');
    for (const width of [320,768,940]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<769});
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Guest home fits viewport width '+width);
        const searchWidth=await evaluate('document.querySelector("#global-search").getBoundingClientRect().width');
        check(searchWidth>=70,`Search input remains usable at ${width}px (actual ${searchWidth}px)`);
        check(await evaluate('document.querySelectorAll(".header-actions .icon-btn").length===4'),'Four customer header shortcuts remain visible at '+width);
    }
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await click('.header-actions [data-action="favorites"]');
    await wait('Boolean(document.querySelector("#auth-form"))');
    check(await evaluate('!getUser()&&S.wishlist.length===0'),'Guest Favorites requires sign-in and exposes no customer records');
    await evaluate('view.accountTab="Profile";view.afterLogin="";go("home")');
    for(const name of ['home','shop','stores','product/p1','store/tech','compare','help']) {
        await route(name);
        check(await evaluate('Boolean(document.querySelector("#app h1"))'),'Customer route has a heading: '+name);
    }
    await route('compare/p1,p2,p3');
    await click('[data-action="compare-remove"][data-id="p3"]');
    await wait('!view.busy');
    check(await evaluate('document.querySelectorAll(".compare-table thead th").length===3'),'Removing a product from a shared comparison updates the displayed columns');
    await click('[data-action="compare-diffs"]');
    check(await evaluate('view.diffOnly&&[...document.querySelectorAll(".compare-table tbody tr")].every(row=>row.classList.contains("different"))'),'Comparison difference filter shows only differing attributes');
    await click('[data-action="compare-diffs"]');
    await evaluate('view.comparisons=[]');
    await route('home');
    const desktop = await send('Page.captureScreenshot',{format:'png'});
    await fs.writeFile(path.join(directory,'home-desktop.png'),Buffer.from(desktop.data,'base64'));
    await route('cart');
    check(await evaluate('Boolean(document.querySelector("#auth-form"))'),'Guest cart prompts sign-in');
    await click('[data-action="auth-tab"][data-tab="Register"]');
    check(await evaluate('document.querySelector("[name=phone]").maxLength===11&&document.querySelector("[name=phone]").minLength===11'),'Phone field requires exactly 11 digits');
    await evaluate(`(()=>{const p=document.querySelector('[name="phone"]');p.value='0917123456789abc';p.dispatchEvent(new Event('input',{bubbles:true}))})()`);
    check(await evaluate('document.querySelector("[name=phone]").value==="09171234567"'),'Pasted phone input is limited to 11 digits');
    check(await evaluate('document.querySelector("#auth-form").dataset.registrationStep==="1"&&document.querySelector("[data-registration-step=\\"2\\"]").hidden'),'Registration opens with account details and hides the address step');
    check(await evaluate('["phone","birth_date","house_number","street","barangay","city","province","postal_code"].every(name=>document.querySelector(`#auth-form [name="${name}"]`)?.required)'),'Registration includes all required contact, birthday and address fields');
    await wait('!document.querySelector("[name=province]").disabled');
    check(await evaluate('document.querySelectorAll("[name=province] option").length===101'),'Registration lists all 100 provinces/regions');
    check(await evaluate('document.querySelector("[name=city]").disabled&&document.querySelector("[name=barangay]").disabled'),'Child location dropdowns wait for their parents');
    check(await evaluate('(()=>{const f=document.getElementById("auth-form");const p=f.elements.province,c=f.elements.city,b=f.elements.barangay;return p.compareDocumentPosition(c)&Node.DOCUMENT_POSITION_FOLLOWING&&c.compareDocumentPosition(b)&Node.DOCUMENT_POSITION_FOLLOWING})()'),'Registration location fields follow Province/Region → City → Barangay order');
    const registrationShot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
    await fs.writeFile(path.join(directory,'registration-desktop.png'),Buffer.from(registrationShot.data,'base64'));
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Extended registration has no horizontal overflow at 390px');
    const registrationMobile=await send('Page.captureScreenshot',{format:'png'});
    await fs.writeFile(path.join(directory,'registration-mobile.png'),Buffer.from(registrationMobile.data,'base64'));
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    const registration = {name:'Customer UI Test',email:'ui-customer@example.test',password:'CustomerTest123!',confirmPassword:'CustomerTest123!',phone:'09171234567',birth_date:'2000-05-14',house_number:'123',street:'Registration Street',barangay:'1381200011',city:'1381200000',province:'1300000000',postal_code:'1611'};
    await evaluate(`(()=>{const f=document.getElementById('auth-form');for(const [name,value]of Object.entries(${JSON.stringify({...registration,confirmPassword:'Mismatch123!'})})){if(!['province','city','barangay'].includes(name))f.elements[name].value=value}f.requestSubmit()})()`);
    await wait('document.querySelector("#auth-error").textContent.includes("match")');
    check(await evaluate('document.querySelector("#auth-form").dataset.registrationStep==="1"&&!getUser()'),'Mismatched passwords block advancement and do not create an account');
    await evaluate(`(()=>{const f=document.getElementById('auth-form');f.elements.confirmPassword.value=${JSON.stringify(registration.password)};f.requestSubmit()})()`);
    await wait('document.querySelector("#auth-form").dataset.registrationStep==="2"');
    check(await evaluate('!getUser()'),'Continuing to the address step does not submit registration to the server');
    const deliveryShot=await send('Page.captureScreenshot',{format:'png'});
    await fs.writeFile(path.join(directory,'registration-address-desktop.png'),Buffer.from(deliveryShot.data,'base64'));
    check(await evaluate('document.querySelector(".auth-form-card").getBoundingClientRect().height<760'),'Delivery step stays compact on desktop');
    await send('Emulation.setDeviceMetricsOverride',{width:940,height:900,deviceScaleFactor:1,mobile:false});
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Two-step layout fits a smaller desktop window');
    const compactShot=await send('Page.captureScreenshot',{format:'png'});
    await fs.writeFile(path.join(directory,'registration-address-940.png'),Buffer.from(compactShot.data,'base64'));
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'Delivery step has no horizontal overflow on mobile');
    const mobileDeliveryShot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
    await fs.writeFile(path.join(directory,'registration-address-mobile.png'),Buffer.from(mobileDeliveryShot.data,'base64'));
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await evaluate(`(()=>{const p=document.querySelector('[name="province"]');p.value='1300000000';p.dispatchEvent(new Event('change',{bubbles:true}))})()`);
    await wait('!document.querySelector("[name=city]").disabled');
    await evaluate(`(()=>{const c=document.querySelector('[name="city"]');c.value='1381200000';c.dispatchEvent(new Event('change',{bubbles:true}))})()`);
    await wait('!document.querySelector("[name=barangay]").disabled');
    await evaluate(`document.querySelector('[name="barangay"]').value='1381200011'`);
    await click('[data-action="registration-back"]');
    check(await evaluate('document.querySelector("#auth-form").dataset.registrationStep==="1"&&document.querySelector("[name=email]").value==="ui-customer@example.test"&&document.querySelector("[name=street]").value==="Registration Street"'),'Going back preserves account and delivery inputs');
    check(await evaluate('document.querySelector("[name=province]").value==="1300000000"&&document.querySelector("[name=city]").value==="1381200000"&&document.querySelector("[name=barangay]").value==="1381200011"'),'Going back preserves all linked address selections');
    await evaluate(`(()=>{const input=document.querySelector('[name="birth_date"]');input.value=S.registrationDate;input.dispatchEvent(new Event('input',{bubbles:true}))})()`);
    check(await evaluate('document.getElementById("registration-age-hint").textContent.includes("not eligible")'),'Registration displays underage eligibility');
    await form('auth-form',{...registration,birth_date:await evaluate('S.registrationDate')});
    await wait('document.querySelector("#auth-error")?.textContent.includes("18")');
    check(await evaluate('!getUser()'),'Underage form submission does not create/sign in a customer');
    await evaluate(`(()=>{const p=document.querySelector('[name="province"]');p.value='0402100000';p.dispatchEvent(new Event('change',{bubbles:true}))})()`);
    check(await evaluate('!document.querySelector("[name=city]").value&&!document.querySelector("[name=barangay]").value&&document.querySelector("[name=barangay]").disabled'),'Changing province clears city and barangay selections');
    check(await evaluate('document.querySelector("[name=name]").value==="Customer UI Test"'),'Changing address dropdown preserves other registration inputs');
    await evaluate(`(()=>{const p=document.querySelector('[name="province"]');p.value='1300000000';p.dispatchEvent(new Event('change',{bubbles:true}))})()`);
    await wait('!document.querySelector("[name=city]").disabled&&document.querySelectorAll("[name=city] option").length===18');
    check(await evaluate('!Array.from(document.querySelector("[name=city]").options).some(option=>option.value==="0402103000")'),'Latest parent selection wins after rapid changes');
    await form('auth-form',registration);
    await wait('Boolean(getUser())&&route()==="account"');
    await wait('Boolean(document.querySelector("[data-action=account-tab]"))');
    check(await evaluate('!getUser().verified'),'Registration uses server verification state');
    check(await evaluate('getUser().birthDate==="2000-05-14"&&getUser().phone==="09171234567"'),'Registration birthday and phone are loaded from server profile');
    check(await evaluate('getUser().addresses.length===1&&getUser().addresses[0].default&&getUser().addresses[0].line.includes("123 Registration Street")'),'Registration address immediately appears as default Home address');
    check(await evaluate('getUser().addresses[0].line.includes("Manggahan, City of Pasig, Metro Manila (NCR) 1611")'),'Registration saves canonical dropdown location names');
    await click('[data-action="account-tab"][data-tab="Addresses"]');
    const originalAddressId=await evaluate('getUser().addresses[0].id');
    await click(`[data-action="address"][data-id="${originalAddressId}"]`);
    await wait('document.querySelector("#modal-form [name=barangay]").value==="1381200011"');
    check(await evaluate('document.querySelector("#modal-form [name=province]").value==="1300000000"&&document.querySelector("#modal-form [name=city]").value==="1381200000"&&document.querySelector("#modal-form [name=street]").value==="Registration Street"'),'Edit address prefills saved province, city, barangay and street');
    const addressInput={label:'Home',recipient_name:'Customer UI Test',phone:'09123456789',house_number:'123',street:'Updated Street',province:'1300000000',city:'1381200000',barangay:'1381200011',postal_code:'1611'};
    await form('modal-form',addressInput);
    await wait('getUser().addresses[0].street==="Updated Street"&&!document.getElementById("dialog").open');
    check(await evaluate('getUser().addresses[0].line.includes("Updated Street, Manggahan")'),'Editing address saves canonical structured details');
    await click('[data-action="address"]');
    check(await evaluate('Boolean(document.querySelector("#modal-form select[name=province]"))&&!document.querySelector("#modal-form textarea[name=address_text]")'),'Add address uses linked dropdowns instead of a free-text address');
    await wait('!document.querySelector("#modal-form [name=province]").disabled');
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2&&document.getElementById("dialog").getBoundingClientRect().width<=innerWidth'),'Address modal fits at 390px');
    const addressShot=await send('Page.captureScreenshot',{format:'png'});
    await fs.writeFile(path.join(directory,'add-address-mobile.png'),Buffer.from(addressShot.data,'base64'));
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await form('modal-form',{...addressInput,label:'Work',street:'Work Street'});
    await wait('getUser().addresses.length===2&&!document.getElementById("dialog").open');
    check(await evaluate('getUser().addresses[0].default'),'Saved address persisted to backend');
    const emails=await fs.readdir(path.join(directory,'mail'));
    const email=JSON.parse(await fs.readFile(path.join(directory,'mail',emails[0]),'utf8'));
    const token=email.body.match(/#verify-email=([a-f0-9]+)/)[1];
    await send('Page.navigate',{url:base+'/#verify-email='+token});
    await wait('window.SaleSync?.ready&&getUser()?.verified');
    check(await evaluate('!location.hash.includes("verify-email")'),'Verification clears token from location');
    await route('product/p1');
    await click('[data-action="add-product"]');
    await wait('S.cart.length===1&&!view.busy');
    await route('product/p8');
    await click('[data-action="variant"][data-id="alt"]');
    await click('[data-action="add-product"]');
    await wait('S.cart.length===2&&!view.busy');
    check(await evaluate('S.cart.some(c=>c.productId==="p8"&&c.variantId==="alt")'),'Selected product option persisted');
    await route('cart');
    await evaluate(`(()=>{const box=document.querySelector('[data-change="select-item"][data-id="p8"]');box.checked=false;box.dispatchEvent(new Event('change',{bubbles:true}))})()`);
    await click('[data-action="checkout"]');
    await wait('route()==="checkout"&&Boolean(view.quote)');
    check(await evaluate('view.quote.items.length===1'),'Server quote contains selected cart items only');
    await click('[data-action="address"]');
    await form('modal-form',{...addressInput,label:'Checkout',house_number:'99',street:'Checkout Street '.repeat(8).trim(),province:'0402100000',city:'0402101000',barangay:'0402101001',postal_code:'4123'});
    await wait('getUser().addresses.some(address=>address.label==="Checkout")&&!document.getElementById("dialog").open&&Boolean(view.quote)');
    check(await evaluate('view.quote.items.length===1'),'Adding an address during checkout preserves selected cart quote');
    await click('[data-action="checkout-address"]');
    await click('.customer-address-picker summary');
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    check(await evaluate('(()=>{const d=document.getElementById("dialog"),o=document.querySelector(".customer-address-options");return d.scrollWidth<=d.clientWidth+1&&o.scrollWidth<=o.clientWidth+1&&document.documentElement.scrollWidth<=innerWidth+2})()'),'Long delivery address options wrap within the mobile dialog');
    const addressPickerShot=await send('Page.captureScreenshot',{format:'png'});
    await fs.writeFile(path.join(directory,'checkout-address-picker-mobile.png'),Buffer.from(addressPickerShot.data,'base64'));
    const checkoutAddressId=await evaluate('getUser().addresses.find(address=>address.label==="Checkout").id');
    const originalCheckoutAddressId=await evaluate('checkoutAddress().id');
    await click(`.customer-address-option [value="${checkoutAddressId}"]`);
    check(await evaluate('!document.querySelector(".customer-address-picker").open&&document.querySelector(".customer-address-picker summary").textContent.includes("Checkout")'),'Choosing a delivery option updates and closes the dropdown');
    await click('#cancel-modal');
    check(await evaluate(`checkoutAddress().id===${JSON.stringify(originalCheckoutAddressId)}`),'Cancelling the address chooser preserves the current delivery address');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await click('[data-action="checkout-address"]');
    await form('modal-form',{address:await evaluate('getUser().addresses.find(address=>address.label==="Checkout").id')});
    await wait('!document.getElementById("dialog").open');
    const checkoutShot=await send('Page.captureScreenshot',{format:'png'});
    await fs.writeFile(path.join(directory,'checkout-desktop.png'),Buffer.from(checkoutShot.data,'base64'));
    await click('[data-action="place-order"]');
    await wait('route()==="orders"&&S.orders.length===1&&!view.busy');
    check(await evaluate('S.orders[0].items.length===1&&S.orders[0].paymentStatus==="Pending"'),'Real order created without fake paid event');
    check(await evaluate('S.orders[0].address.includes("Amuyong, Alfonso, Cavite 4123")'),'Order snapshots the structured address selected during checkout');
    check(await evaluate('S.cart.length===1&&S.cart[0].productId==="p8"'),'Unchecked cart option retained');
    await reload(); await wait('S.orders.length===1');
    check(await evaluate('Boolean(getUser())&&S.cart.length===1'),'Account, cart and order survive reload from server');
    await route('orders'); await click('[data-action="invoice"]');
    check(await evaluate('document.querySelector(".invoice-document").textContent.includes(S.orders[0].id)'),'Order document uses saved order');
    check(await evaluate('document.getElementById("dialog").dataset.readonly==="true"&&!document.querySelector("#dialog [download]")&&!/download html/i.test(document.getElementById("dialog").textContent)'),'View Invoice has no HTML download option');
    await click('#close-modal');
    await click('[data-action="cancel-order"]'); await form('modal-form',{});
    await wait('S.orders[0].status==="cancelled"&&!document.getElementById("dialog").open');
    check(await evaluate('getP("p1").stock===28'),'Cancellation releases real stock');
    await route('notifications'); await click('[data-action="mark-read"]');
    await wait('S.notifications.every(n=>n.read)&&!view.busy');
    await route('account'); await click('[data-action="profile-edit"]');
    await form('modal-form',{name:'Updated UI Customer'});
    await wait('getUser().name==="Updated UI Customer"&&!document.getElementById("dialog").open');
    check(await evaluate('getUser().name==="Updated UI Customer"'),'Profile edit saved to server');
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    for(const name of ['home','shop','stores','product/p1','cart','orders','account','notifications']) {
        await route(name);
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'No horizontal overflow at 390px: '+name);
    }
    await route('home');
    const mobile=await send('Page.captureScreenshot',{format:'png'});
    await fs.writeFile(path.join(directory,'home-mobile.png'),Buffer.from(mobile.data,'base64'));
    await click('[data-action="logout"]'); await wait('!getUser()&&!view.busy');
    await route('login'); await form('auth-form',{email:'ui-customer@example.test',password:'CustomerTest123!'});
    await wait('Boolean(getUser())&&!view.busy');
    check(await evaluate('getUser().name==="Updated UI Customer"'),'Existing server account signs in');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await route('product/p1');await click('[data-action="wishlist"]');
    await wait('S.wishlist.length===1&&!view.busy');
    await reload();await wait('S.wishlist.length===1');
    check(await evaluate('document.querySelector(".header-actions [data-action=favorites] .count-bubble").textContent==="1"'),'Header Favorites badge reflects persisted products');
    await click('.header-actions [data-action="favorites"]');
    await wait('view.renderedRoute==="account"&&view.accountTab==="Saved"&&Boolean(document.querySelector(".account-content"))');
    check(await evaluate('document.querySelector(".account-content h2").textContent==="Favorites"'),'Top Favorites icon opens the customer favorite products');
    check(await evaluate('document.querySelector(".account-content").textContent.includes("Wireless Noise-Cancelling Headphones")'),'Saved products loaded across browser reload');
    await click('[data-action="saved-remove"]');await wait('S.wishlist.length===0&&!view.busy');
    check(await evaluate('!document.querySelector(".header-actions [data-action=favorites] .count-bubble")'),'Removing a favorite updates the top icon badge');
    await click('[data-action="account-tab"][data-tab="Preferences"]');
    await click('[data-preference="messages"]');
    await wait('getUser().notificationPrefs.messages===false');
    await reload();
    await click('[data-action="account-tab"][data-tab="Preferences"]');
    check(await evaluate('!document.querySelector("[data-preference=messages]").checked'),'Email preferences persist across reload');
    await route('product/p1');await click('[data-action="chat-start"]');
    await wait('route()==="messages"&&Boolean(document.querySelector("#chat-form"))');
    await form('chat-form',{text:'Hello, is this product available?'});
    await wait('S.chats[0].messages.some(m=>m.text==="Hello, is this product available?")');
    fixture(`$db->run("INSERT INTO customer_messages(thread_id,sender_id,body,client_key) SELECT thread_id,s.user_id,'Yes, it is available.','seller-browser-reply' FROM customer_threads t JOIN seller_profiles s USING(seller_id) LIMIT 1");`);
    await route('account');await route('messages');
    check(await evaluate('document.querySelector(".chat-messages").textContent.includes("Yes, it is available.")'),'Seller reply appears in customer inbox');
    check(fixture(`echo $db->one("SELECT COUNT(*) AS n FROM customer_messages WHERE client_key='seller-browser-reply' AND read_at IS NOT NULL")['n'];`)==='1','Reading conversation persists incoming read state');
    const messageShot=await send('Page.captureScreenshot',{format:'png'});await fs.writeFile(path.join(directory,'messages-desktop.png'),Buffer.from(messageShot.data,'base64'));
    await route('account');await click('[data-action="account-tab"][data-tab="Seller Application"]');
    await click('[data-action="apply-seller"]');
    await form('modal-form',{store_name:'Customer test shop',business_name:'Customer test business',business_type:'Sole proprietor',contact_name:'UI Customer',contact_email:'ui-customer@example.test',phone:'09123456789',address_text:'123 Business Street, Pasig',description:'Handmade accessories and gifts'});
    await wait('S.applications.length===1&&!document.getElementById("dialog").open');
    check(await evaluate('document.querySelector(".account-content").textContent.includes("pending")&&getUser().role==="Customer"'),'Application status visible without granting seller permissions');
    fixture(`$db->run("INSERT INTO vouchers(code,name,scope,kind,value,starts_at,expires_at,active)VALUES('BROWSER100','Browser discount','platform','fixed',10000,'2020-01-01T00:00:00Z','2099-01-01T00:00:00Z',1)");`);
    await route('home');await route('account');await click('[data-action="account-tab"][data-tab="Vouchers"]');
    await click('[data-action="claim"]');await wait('S.claims.length===1&&!view.busy');
    await route('cart');await click('[data-action="checkout"]');await wait('route()==="checkout"&&Boolean(view.quote)');
    await click('[data-action="voucher-pick"][data-scope="platform"]');
    const voucher=await evaluate('S.vouchers[0].id');
    await form('modal-form',{voucher});await wait('view.quote.merchandise_discount===10000&&!document.getElementById("dialog").open');
    check(await evaluate('view.quote.total===view.quote.subtotal+view.quote.shipping_fee-10000'),'Voucher picker displays authoritative discounted total');
    await click('[data-action="place-order"]');await wait('S.orders.length===2&&route()==="orders"&&!view.busy');
    check(await evaluate('S.orders.some(o=>o.discount===100)'),'Order retains applied voucher discount');
    fixture(`$id=$db->one("SELECT MAX(order_id) AS id FROM orders")['id'];$db->run("UPDATE payments SET status='paid' WHERE order_id=?",[$id]);$db->run("UPDATE orders SET status='completed' WHERE order_id=?",[$id]);$db->run("INSERT INTO order_status_history(order_id,status)VALUES(?,'completed')",[$id]);`);
    await route('home');await route('orders');await click('[data-action="return"]');
    const item=await evaluate('S.orders.find(o=>o.completed).items[0].orderItemId');
    const png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1EAAAAASUVORK5CYII=';
    await evaluate(`(()=>{const bytes=Uint8Array.from(atob('${png}'),c=>c.charCodeAt(0)),dt=new DataTransfer();dt.items.add(new File([bytes],'evidence.png',{type:'image/png'}));document.querySelector('[name=attachments]').files=dt.files})()`);
    await form('modal-form',{order_item_id:String(item),quantity:'1',reason:'Damaged item',solution:'return_refund',explanation:'There is damage on the received item.'});
    await wait('S.returns.length===1&&!document.getElementById("dialog").open');
    await click('[data-action="return-case"]');
    check(await evaluate('document.querySelector("#modal-form").textContent.includes("requested")&&document.querySelector("#modal-form img").complete'),'Case timeline and private evidence shown');
    check(await evaluate(`(async()=>{const r=await fetch(document.querySelector('#modal-form img').src);return r.ok&&r.headers.get('content-type')==='image/png'})()`),'Private evidence endpoint serves the authenticated customer image');
    await click('#close-modal');
    fixture(`$db->run("UPDATE return_cases SET status='rejected',seller_reply='Please provide more details'");`);
    await route('home');await route('orders');await click('[data-action="return-appeal"]');
    await form('modal-form',{explanation:'Please reconsider this evidence and review my case.'});
    await wait('S.returns[0].status==="Escalated"&&!document.getElementById("dialog").open');
    check(await evaluate('document.querySelector(".order-card").parentElement.textContent.includes("Escalated")'),'Appeal submitted and status updated');
    await route('account');await click('[data-action="account-tab"][data-tab="Security"]');await click('[data-action="password-change"]');
    for (const name of ['current_password','new_password','confirm_password']) {
        await click(`[data-action="password-visibility"][data-target="account-${name}"]`);
        check(await evaluate(`document.querySelector('[name="${name}"]').type==='text'&&document.querySelector('[data-target="account-${name}"]').getAttribute('aria-pressed')==='true'`),'Account password visibility can be enabled: '+name);
        await click(`[data-action="password-visibility"][data-target="account-${name}"]`);
        check(await evaluate(`document.querySelector('[name="${name}"]').type==='password'`),'Account password visibility can be disabled: '+name);
    }
    await form('modal-form',{current_password:'CustomerTest123!',new_password:'UpdatedCustomer123!',confirm_password:'UpdatedCustomer123!'});
    await wait('!document.getElementById("dialog").open');
    await click('[data-action="logout"]');await wait('!getUser()&&!view.busy');await route('login');
    await form('auth-form',{email:'ui-customer@example.test',password:'UpdatedCustomer123!'});await wait('Boolean(getUser())&&!view.busy');
    check(await evaluate('getUser().name==="Updated UI Customer"'),'Changed password signs in successfully');
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    for(const name of ['messages','orders','account']){await route(name);check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'New customer features fit at 390px: '+name);}
    if(process.env.SALESYNC_ADVERSARIAL_QA==='1') {
        const {auditCustomer}=await import('./customer-audit.mjs');
        adversarialFindings = await auditCustomer({evaluate,fixture,route,click,wait,directory});
    }
    check(exceptions.length===0,'No browser exceptions: '+exceptions.join('\n'));
    check(failedResources.length===0,'No missing scripts/styles/images: '+failedResources.join('\n'));
    const report={checks,exceptions,failedResources,screenshots:directory,adversarialFindings};
    await fs.writeFile(path.join(directory,'results.json'),JSON.stringify(report,null,2));
    const failures=adversarialFindings.filter(f=>f.passed===false).length;
    if(failures) process.exitCode=1;
    console.log(`${failures?'AUDIT FAILED':'PASS'}: ${checks} UI checks; ${failures} adversarial failures. Screenshots: ${directory}`);
} catch (error) {
    console.error(JSON.stringify({exceptions,failedResources,body:ws ? await evaluate('document.body.innerText.slice(0,2200)').catch(()=> '') : ''},null,2));
    throw error;
} finally {
    ws?.close();
    if(target) await fetch(`${debug}/json/close/${target.id}`).catch(()=>{});
    server.kill();
    await new Promise(resolve=>server.once('exit',resolve));
}
