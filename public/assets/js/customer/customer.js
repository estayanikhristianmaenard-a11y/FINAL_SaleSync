import { api } from './api.js';
import { applyCart, clearPrivateState, loadPrivate, refresh, selection } from './customer-data.js';
import { mount as mountLocations, fields as locationFields } from './location-picker.js';
import { featureAction, quote, voucherIds, readChat, sendMessage } from './customer-features.js';
import { loadConversations } from './customer-data.js';
import { chooseDeliveryAddress, updateAddressChoice, viewInvoice } from './customer-controls.js';

export { api };

window.addEventListener('salesync:session-ended', event => {
    const wasAuthenticated=Boolean(userId);
    const destination=route();
    clearPrivateState();
    view.error=''; view.authTab='Sign in';
    if (event.detail.redirect && wasAuthenticated) {
        view.afterLogin=destination;
        go('login');
        toast('Your session ended. Please sign in again.',true);
    } else if (SaleSync.ready) render();
});

window.customerDisabledActions = new Set(['follow','forgot','go-seller','go-sell','received','return-shipped','review','report-review']);

export async function verifyEmailFromLink() {
    if (!location.hash.startsWith('#verify-email=')) return null;
    const token = decodeURIComponent(location.hash.slice('#verify-email='.length));
    const result = await api('/auth/verify-email', { method:'POST', body:{ token } });
    history.replaceState(null, '', location.pathname + location.search + '#/account');
    return result;
}

function requireCustomer() {
    if (getUser()) return true;
    view.afterLogin = route();
    go('login');
    return false;
}

function registrationAge(birthday) {
    const parts = birthday?.split('-').map(Number);
    const today = S.registrationDate?.split('-').map(Number);
    if (parts?.length !== 3 || today?.length !== 3 || parts.some(value => !Number.isInteger(value))) return null;
    let age = today[0] - parts[0];
    if (today[1] < parts[1] || today[1] === parts[1] && today[2] < parts[2]) age--;
    return age;
}

function showRegistrationAge(birthday) {
    const hint = $('#registration-age-hint');
    if (!hint) return;
    const age = registrationAge(birthday);
    hint.textContent = age === null ? `You must be at least ${S.minimumCustomerAge} years old to register.`
        : age < 0 ? 'Birthday cannot be in the future.'
        : age < S.minimumCustomerAge ? `Age ${age}: not eligible. You must be at least ${S.minimumCustomerAge} years old.`
        : `Age ${age}: eligible to register.`;
    hint.className = age !== null && age < S.minimumCustomerAge ? 'modal-error' : 'small muted';
}

async function addProduct(id, variant = 'std', quantity = 1, buyNow = false) {
    if (!requireCustomer()) return;
    const product = getP(id);
    guard(product, 'Product is unavailable.');
    const current = S.cart.find(item => item.productId === id && item.variantId === variant)?.qty || 0;
    const result = await api(`/cart/items/${product.backendId}`, { method:'PUT', body:{ variant_id:variant, quantity:buyNow ? quantity : current + quantity } });
    applyCart(result);
    if (buyNow) {
        S.cart.forEach(item => { item.selected = item.productId === id && item.variantId === variant; });
        await checkout();
    } else { render(); toast('Added to cart'); }
}

async function checkout() {
    guard(requireCustomer(), 'Please sign in.');
    view.checkoutVoucher='';view.shippingVoucher='';view.shopVoucher={};
    view.quote = await quote();
    go('checkout');
}

function editAddress(addressId) {
    const address = getUser().addresses.find(item => item.id === addressId);
    openModal(address ? 'Edit address' : 'Add address',
        field('label','Address label','text',address?.label || 'Home','required maxlength="40"') +
        field('recipient_name','Recipient name','text',address?.name || getUser().name,'required maxlength="100"') +
        field('phone','Phone','tel',address?.phone || getUser().phone || '','required inputmode="numeric" minlength="11" maxlength="11" pattern="[0-9]{11}" title="Enter exactly 11 digits, e.g. 09171234567"') +
        (address && !address.province_code ? `<div class="notice"><p>Saved address: ${esc(address.line)}</p><p>Select its location and enter the house/street details below to update it.</p></div>` : '') +
        field('house_number','House / building / unit number','text',address?.house_number || '','required maxlength="100" autocomplete="address-line1"') +
        field('street','Street','text',address?.street || '','required maxlength="150" autocomplete="address-line2"') +
        locationFields() +
        field('postal_code','Postal code','text',address?.postal_code || '','required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="postal-code"') +
        `<label class="row"><input type="checkbox" name="is_default" ${address?.default ? 'checked' : ''}> Default address</label>`,
        'Save address', async data => {
            guard(data.province && data.city && data.barangay, 'Select your province/region, city/municipality and barangay.');
            await api(address ? `/addresses/${address.id}` : '/addresses', { method:address ? 'PATCH' : 'POST', body:{ ...data, is_default:data.is_default === 'on' } });
            await loadPrivate();
            if (route() === 'checkout') view.quote = await quote();
            toast('Address saved');
        });
    void mountLocations($('#modal-form'), { province:address?.province_code, city:address?.city_code, barangay:address?.barangay_code });
}

async function startPayment(order) {
    guard(order, 'Order not found.');
    const result = await api(`/orders/${order.backendId}/payment`, { method:'POST' });
    if (result.status === 'paid') { await refresh(); go('orders'); return; }
    guard(result.checkout_url, 'Payment session is awaiting reconciliation. Open My Purchases later.');
    const url = new URL(result.checkout_url);
    guard(url.protocol === 'https:' && url.hostname === 'checkout.paymongo.com', 'Invalid payment destination.');
    location.assign(url.href);
}

async function placeOrder() {
    const address = checkoutAddress();
    guard(address, 'Add a delivery address first.');
    guard(getUser()?.verified, 'Verify your email in My Account before checkout.');
    const storageKey = `salesync-checkout:${userId}`;
    const body = { address_id:Number(address.id), items:selection(),voucher_ids:voucherIds(),quote_token:view.quote?.quote_token };
    const fingerprint = JSON.stringify(body);
    let attempt;
    try { attempt = JSON.parse(sessionStorage.getItem(storageKey)); } catch { /* no previous attempt */ }
    // An unresolved request always reuses its original payload/key, including after reload.
    if (attempt && attempt.fingerprint !== fingerprint) {
        throw new Error('An earlier checkout has an uncertain result. Open My Purchases before starting another purchase.');
    }
    attempt ??= { key:crypto.randomUUID(), fingerprint, body };
    sessionStorage.setItem(storageKey, JSON.stringify(attempt));
    let order;
    try {
        order = await api('/checkout', { method:'POST', body:attempt.body, idempotencyKey:attempt.key });
    } catch (error) {
        if (error.status && error.status < 500) sessionStorage.removeItem(storageKey);
        if (error.fields?.quote) {
            await loadPrivate();
            view.quote = await quote();
            render();
        }
        throw error;
    }
    sessionStorage.removeItem(storageKey);
    view.checkoutVoucher='';view.shippingVoucher='';view.shopVoucher={};
    await refresh();
    go('orders');
    toast(S.paymentConfigured ? 'Order created. Continue to payment.' : 'Order created. Payment setup is pending; your order is saved in My Purchases.');
    if (S.paymentConfigured) await startPayment(getO(order.order_id));
}

async function act(action, element) {
    const data = element.dataset;
    if (window.customerDisabledActions.has(action)) return;
    if (await featureAction(action,data)) return;
    switch (action) {
        case 'route': go(data.to); return;
        case 'reload': location.reload(); return;
        case 'signin': go('login'); return;
        case 'logout': await api('/auth/logout', { method:'POST' }); userId = ''; S.users = []; view.quote = null; view.checkoutVoucher='';view.shippingVoucher='';view.shopVoucher={};view.chat='';view.authTab = 'Sign in'; await refresh(); go('home'); return;
        case 'auth-tab': view.authTab = data.tab; render(); return;
        case 'registration-back': setRegistrationStep($('#auth-form'), 1); return;
        case 'password-visibility': {
            const input = document.getElementById(data.target);
            const revealed = input.type === 'password';
            input.type = revealed ? 'text' : 'password';
            const label = `${revealed ? 'Hide' : 'Show'} ${data.label || 'password'}`;
            element.setAttribute('aria-label', label); element.title = label;
            element.setAttribute('aria-pressed', String(revealed));
            element.innerHTML = revealed ? uiIcon('eye').replace('</svg>', '<path d="M3 3l18 18"/></svg>') : uiIcon('eye');
            return;
        }
        case 'hero-shop': view.category = 'All'; view.shop = ''; go('shop'); return;
        case 'banner': view.hero = Number(data.index); render(); return;
        case 'hot-search': view.search = data.value; view.category = 'All'; go('shop'); return;
        case 'nav-strip': {
            view.search = ''; view.shop = ''; view.subcategory = '';
            if (data.value === 'Home' || data.value === 'Stores') { go(data.value.toLowerCase()); return; }
            view.category = data.value === 'Shop' ? 'All' : data.value === 'Home & Living' ? 'Home' : data.value;
            go('shop'); return;
        }
        case 'category': view.category = data.category; view.shop = ''; view.subcategory = ''; view.search = ''; go('shop'); return;
        case 'subcategory': view.subcategory = data.value; render(); return;
        case 'clear-search': Object.assign(view, { search:'', category:'All', shop:'', subcategory:'', priceMax:0, rating:0, inStock:false }); render(); return;
        case 'discover-sort': view.sort = data.value; render(); return;
        case 'see-more': view.seeMore = (view.seeMore || 25) + 25; render(); return;
        case 'product': view.variant = 'std'; view.gallery = 0; view.qty = 1; go(`product/${data.id}`); return;
        case 'gallery': view.gallery = Number(data.index); render(); return;
        case 'product-tab': view.productTab = data.tab; render(); return;
        case 'variant': view.variant = data.id; render(); return;
        case 'pqty': view.qty = Math.max(1, Math.min(available(view.product), (view.qty || 1) + Number(data.change))); render(); return;
        case 'zoom-product': openModal(getP(data.id).name, art(getP(data.id), view.gallery), 'Close', () => {}, true); return;
        case 'quick-add': case 'compare-cart': await addProduct(data.id); return;
        case 'add-product': await addProduct(data.id, view.variant, view.qty); return;
        case 'buy-now': await addProduct(data.id, view.variant, view.qty, true); return;
        case 'compare-buy': await addProduct(data.id, 'std', 1, true); return;
        case 'compare-option-cart': await addProduct(data.id, document.querySelector(`[name="compare-option-${CSS.escape(data.id)}"]`)?.value || 'std'); return;
        case 'cart-qty': {
            const item = S.cart.find(item => item.productId === data.id && item.variantId === data.variant);
            const quantity = item.qty + Number(data.delta);
            guard(quantity > 0, 'Use Delete to remove this item.');
            applyCart(await api(`/cart/items/${getP(data.id).backendId}`, { method:'PUT', body:{ quantity, variant_id:data.variant } })); render(); return;
        }
        case 'cart-remove': applyCart(await api(`/cart/items/${getP(data.id).backendId}`, { method:'DELETE', body:{ variant_id:data.variant } })); render(); return;
        case 'checkout': await checkout(); return;
        case 'place-order': await placeOrder(); return;
        case 'recover-checkout': {
            const key = `salesync-checkout:${userId}`;
            const attempt = JSON.parse(sessionStorage.getItem(key));
            guard(attempt?.key && attempt?.body, 'No unresolved checkout was found.');
            try {
                await api('/checkout', { method:'POST', body:attempt.body, idempotencyKey:attempt.key });
                sessionStorage.removeItem(key);
                view.checkoutVoucher='';view.shippingVoucher='';view.shopVoucher={};
                await refresh(); go('orders'); toast('Previous checkout confirmed.');
            } catch (error) {
                if (error.status && error.status < 500) { sessionStorage.removeItem(key); render(); }
                throw error;
            }
            return;
        }
        case 'account-tab': view.accountTab = data.tab; render(); return;
        case 'favorites': view.accountTab = 'Saved'; view.savedSubtab = 'Products'; if (!requireCustomer()) { view.afterLogin = 'account'; return; } go('account'); return;
        case 'saved-subtab': view.savedSubtab = data.tab; render(); return;
        case 'voucher-tab': view.voucherTab = data.tab; render(); return;
        case 'profile-edit': openModal('Edit profile', field('name','Full name','text',getUser().name,'required maxlength="100"'), 'Save profile', async input => { await api('/profile', { method:'PATCH', body:{ name:input.name } }); await loadPrivate(); toast('Profile saved'); }); return;
        case 'address': editAddress(data.id); return;
        case 'address-default': {
            await api(`/addresses/${data.id}`, { method:'PATCH', body:{ is_default:true } }); await loadPrivate(); render(); return;
        }
        case 'address-delete': choice('Delete address', 'Remove this saved delivery address?', async () => { await api(`/addresses/${data.id}`, { method:'DELETE' }); await loadPrivate(); toast('Address deleted'); }); return;
        case 'checkout-address': chooseDeliveryAddress(); return;
        case 'resend-verification': await api('/auth/resend-verification', { method:'POST' }); toast('Verification email queued.'); return;
        case 'order-tab': view.orderTab = data.tab; render(); return;
        case 'retry-pay': await startPayment(getO(data.id)); return;
        case 'refresh-payment': await api(`/orders/${getO(data.id).backendId}/refresh-payment`, { method:'POST' }); await refresh(); render(); toast('Payment status refreshed'); return;
        case 'cancel-order': choice('Cancel order', 'Cancel this unpaid order and release its reserved stock?', async () => { await api(`/orders/${getO(data.id).backendId}/cancel`, { method:'POST' }); await refresh(); toast('Order cancelled'); }); return;
        case 'track': { const order = getO(data.id); openModal(`Order ${order.id}`, order.shipments.map(shipment => `<h3>${esc(getStore(shipment.storeId)?.name)}</h3><div class="timeline">${shipment.history.map(event => `<div class="event"><b>${esc(event.status)}</b><p>${dateTime(event.time)}</p></div>`).join('')}</div>`).join(''), 'Close', () => {}, true); return; }
        case 'invoice': viewInvoice(getO(data.id)); return;
        case 'buy-again': for (const item of getO(data.id).items) await addProduct(item.id,item.variantId,item.qty); go('cart'); return;
        case 'notification-filter': view.notificationType = data.type; render(); return;
        case 'notification-unread': view.notificationsUnread = !view.notificationsUnread; render(); return;
        case 'notification-open': {
            const notice=S.notifications.find(n=>n.id===data.id);
            await api(`/notifications/${data.id}/read`, { method:'PATCH' }); await loadPrivate();
            if(notice?.context==='thread'){view.chat=String(notice.contextId);go('messages');}
            else if(notice?.context==='application'){view.accountTab='Seller Application';go('account');}
            else if(notice?.type==='Account'){go('account');}else go('orders');return;
        }
        case 'mark-read': for (const notice of S.notifications.filter(notice => !notice.read)) await api(`/notifications/${notice.id}/read`, { method:'PATCH' }); await loadPrivate(); render(); return;
        case 'pop': if (!requireCustomer()) return; go(data.pop === 'cart' ? 'cart' : 'notifications'); return;
        case 'store-view': go(`store/${data.id}`); return;
        case 'store-category': view.storeCategory = data.category; render(); return;
        case 'store-search': view.storeSearch = $('#store-directory-search')?.value || ''; render(); return;
        case 'shop-tab': (view.storeBrowse[data.id]).tab = data.tab; render(); return;
        case 'shop-collection': Object.assign(view.storeBrowse[data.id], { category:data.category, tab:'All Products' }); render(); return;
        case 'shop-reset': Object.assign(view.storeBrowse[data.id], { category:'', query:'', inStock:false }); render(); return;
        case 'compare-toggle': if (isCompared(data.id)) view.comparisons = view.comparisons.filter(id => id !== data.id); else { guard(compareIds().length < 4, 'Compare up to four products.'); view.comparisons.push(data.id); } render(); return;
        case 'compare-remove': {
            const sharedIds = route().startsWith('compare/') ? route().split('/')[1].split(',').slice(0, 4) : null;
            view.comparisons = (sharedIds || view.comparisons).filter(id => id !== data.id && getP(id));
            if (sharedIds) go(view.comparisons.length ? `compare/${view.comparisons.join(',')}` : 'compare');
            else render();
            return;
        }
        case 'compare-clear': view.comparisons = []; render(); return;
        case 'compare-open': go('compare'); return;
        case 'compare-collapse': view.compareCollapsed = !view.compareCollapsed; render(); return;
        case 'compare-diffs': view.diffOnly = !view.diffOnly; render(); return;
        default: toast('This feature is not available yet.', true);
    }
}

async function runAction(action, element) {
    if (view.busy) return;
    view.busy = true;
    const button = element.matches?.('button') ? element : null;
    if (button) button.disabled = true;
    try { await act(action, element); }
    catch (error) { toast(error.message || 'Request failed.', true); }
    finally {
        view.busy = false;
        if (button?.isConnected && !window.customerDisabledActions.has(action)) button.disabled = false;
        if (action === 'place-order' && route() === 'checkout') render();
    }
}

document.addEventListener('click', event => {
    const button = event.target.closest('[data-action]');
    if (!button || button.disabled) return;
    event.preventDefault();
    void runAction(button.dataset.action, button);
});
document.addEventListener('submit', async event => {
    event.preventDefault();
    if (event.target.id==='chat-form') { await sendMessage(event.target);return; }
    if (event.target.id === 'modal-form') {
        if (view.modalBusy) return;
        view.modalBusy = true; $('#modal-btn').disabled = true;
        try { await view.modalSubmit?.(Object.fromEntries(new FormData(event.target))); closeModal(); render(); }
        catch (error) { $('#modal-error').textContent = error.message; }
        finally { view.modalBusy = false; $('#modal-btn').disabled = false; }
        return;
    }
    if (event.target.id === 'auth-form') {
        if (view.busy) return;
        view.busy = true;
        const submit = event.target.querySelector('[type="submit"]'); submit.disabled = true;
        try {
            const data = Object.fromEntries(new FormData(event.target));
            if (view.authTab === 'Register') {
                if (!validateRegistrationFields(event.target, 1)) return;
                if (data.password !== data.confirmPassword) setRegistrationStep(event.target, 1, false);
                guard(data.password === data.confirmPassword, 'Passwords do not match.');
                const age = registrationAge(data.birth_date);
                if (age === null || age < S.minimumCustomerAge) setRegistrationStep(event.target, 1, false);
                guard(age !== null && age >= S.minimumCustomerAge, `You must be at least ${S.minimumCustomerAge} years old to register.`);
                if (event.target.dataset.registrationStep === '1') {
                    setRegistrationStep(event.target, 2);
                    return;
                }
                if (!validateRegistrationFields(event.target, 2)) return;
                guard(data.province && data.city && data.barangay, 'Select your province/region, city/municipality and barangay.');
            }
            const result = await api(view.authTab === 'Register' ? '/auth/register' : '/auth/login', { method:'POST', body:data });
            await refresh();
            const target = view.authTab === 'Register' ? 'account' : view.afterLogin || 'home'; view.afterLogin = '';
            if (target === 'checkout') { await checkout(); } else { go(target); }
            toast(result.verification_delivery === 'unavailable' ? 'Account created. Email delivery is unavailable; try resending later.' : view.authTab === 'Register' ? 'Account created. Please verify your email.' : 'Signed in');
        } catch (error) {
            if (view.authTab === 'Register' && error.fields && Object.keys(error.fields).some(key => ['name','email','password','phone','birth_date'].includes(key))) setRegistrationStep(event.target, 1, false);
            const message = $('#auth-error');
            if (message) { message.textContent = error.message; message.className = 'modal-error'; message.focus({ preventScroll:true }); }
        }
        finally { view.busy = false; if (submit.isConnected) submit.disabled = false; }
        return;
    }
    if (event.target.id === 'search-form') {
        Object.assign(view, { search:$('#global-search').value, category:$('#search-category').value, shop:'', subcategory:'', seeMore:25 }); go('shop'); return;
    }
    if (event.target.id === 'store-search-form') {
        const id = route().split('/')[1]; Object.assign(view.storeBrowse[id], { query:new FormData(event.target).get('query'), tab:'All Products' }); render();
    }
});
document.addEventListener('change', event => {
    const target = event.target;
    if (target.name === 'address' && target.closest('.customer-address-picker')) updateAddressChoice(target);
    if(target.dataset.preference) {
        const key=target.dataset.preference,value=target.checked;target.disabled=true;
        void api('/preferences',{method:'PATCH',body:{[key]:value}}).then(p=>{getUser().notificationPrefs=p;toast('Email preference saved');}).catch(error=>{target.checked=!value;toast(error.message,true);}).finally(()=>{target.disabled=false;});return;
    }
    if(target.id==='message-search'){view.messageQuery=target.value;render();return;}
    if (target.name === 'birth_date' && target.closest('#auth-form')) showRegistrationAge(target.value);
    const properties = { 'price-filter':'priceMax', 'mobile-price':'priceMax', 'rating-filter':'rating', 'stock-filter':'inStock',
        'mobile-stock':'inStock', 'mobile-category':'category', 'catalog-store':'shop', 'sort-products':'sort' };
    if (properties[target.id]) { view[properties[target.id]] = target.type === 'checkbox' ? target.checked : ['priceMax','rating'].includes(properties[target.id]) ? Number(target.value) : target.value; render(); }
    if (target.dataset.storeFilter) { view.storeBrowse[target.dataset.storeFilter][target.dataset.field] = target.type === 'checkbox' ? target.checked : target.value; render(); }
    if (target.dataset.change?.startsWith('select-')) {
        S.cart.forEach(item => {
            if (target.dataset.change === 'select-all' || target.dataset.change === 'select-store' && getP(item.productId).storeId === target.dataset.store || target.dataset.change === 'select-item' && item.productId === target.dataset.id && item.variantId === target.dataset.variant) item.selected = target.checked;
        }); view.quote = null; render();
    }
});
document.addEventListener('input', event => {
    if (event.target.name === 'phone') event.target.value = event.target.value.replace(/[^0-9]/g, '').slice(0, 11);
    if (event.target.name === 'birth_date' && event.target.closest('#auth-form')) showRegistrationAge(event.target.value);
});
document.addEventListener('keydown', event => {
    if (event.key === 'Enter' && event.target.id === 'store-directory-search') { event.preventDefault(); void runAction('store-search', { dataset:{} }); }
    if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.product-name[tabindex]')) { event.preventDefault(); void runAction('product', event.target); }
});
$('#close-modal').addEventListener('click', () => { if (!view.modalBusy) closeModal(); });
$('#cancel-modal').addEventListener('click', () => { if (!view.modalBusy) closeModal(); });
$('#dialog').addEventListener('cancel', event => { if (view.modalBusy) event.preventDefault(); });

async function routeChanged() {
    if (view.loading) return;
    try {
        const path = route();
        if (['seller','maintenance','management'].includes(path.split('/')[0])) { go('home'); return; }
        if (path === 'profile') { go('account'); return; }
        if (path.startsWith('verify-email=')) { await verifyEmailFromLink(); await refresh(); }
        if (path.startsWith('orders/') && userId) { await api(`/orders/${Number(path.split('/')[1])}/refresh-payment`, { method:'POST' }); await refresh(); }
        if (['orders','account','notifications','cart','messages'].includes(path.split('/')[0]) && userId) await loadPrivate();
        if(path==='messages'&&userId&&!view.messageUnread) await readChat(view.chat||S.chats[0]?.id);
        if (path === 'checkout' && userId) view.quote = await quote();
        render(); window.scrollTo(0,0);
    } catch (error) { toast(error.message, true); if (route() === 'checkout') go('cart'); else render(); }
}
window.addEventListener('hashchange', routeChanged);
document.addEventListener('visibilitychange', async () => {
    // Keep an unfinished sign-in/registration form intact when returning to the tab.
    if (!userId && $('#auth-form')) return;
    if (!document.hidden && SaleSync.ready && !view.busy) {
        try { await refresh(); await routeChanged(); } catch (error) { toast(error.message, true); }
    }
});
SaleSync.actions.dispatch = (action, data = {}) => runAction(action, { dataset:data });
SaleSync.selectors.currentUser = () => getUser();
SaleSync.selectors.product = id => getP(id);
SaleSync.selectors.available = available;
setInterval(async()=>{
    if(!SaleSync.ready||document.hidden||route()!=='messages'||!userId||view.busy||$('#chat-form')?.dataset.sending)return;
    try {
        const input=$('#chat-form [name="text"]'),draft=input?.value||'';
        if(draft||document.activeElement===input)return;
        await loadConversations();if(!view.messageUnread)await readChat(view.chat||S.chats[0]?.id);render();
    } catch { /* Next refresh retries without disrupting a draft. */ }
},15000);
try {
    await verifyEmailFromLink();
    await refresh();
    SaleSync.ready = true;
    await routeChanged();
} catch (error) {
    view.loading = false;
    if ([401,403].includes(error.status) && !userId) {
        view.error=''; view.authTab='Sign in'; SaleSync.ready=true; go('login'); render();
    } else {
        view.error = error.message; render();
    }
}
