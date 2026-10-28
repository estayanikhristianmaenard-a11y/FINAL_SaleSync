/* Mount only customer views. Business mutations are handled by customer.js through the API. */
const customerPrivateRoutes = new Set(['cart','checkout','orders','account','notifications','messages','payment','result']);
function unavailablePanel(title, message) {
    return `<section class="panel empty"><h1 class="h2">${esc(title)}</h1><p>${esc(message)}</p>${btn('route','Continue shopping','data-to="shop"','primary')}</section>`;
}
function render() {
    const oldChatForm=document.getElementById('chat-form');
    if (getUser() && oldChatForm) {
        view.chatDrafts ??= {};
        view.chatDrafts[oldChatForm.dataset.thread]=oldChatForm.elements.text.value;
    }
    const path = route(), [page, parameter] = path.split('/');
    document.body.className = 'portal-customer';
    if (view.loading) { app.innerHTML = unavailablePanel('Loading SaleSync', 'Getting your latest account and catalogue…'); return; }
    if (view.error) { app.innerHTML = unavailablePanel('Unable to load SaleSync', view.error) + btn('reload','Try again','','primary'); return; }
    if (customerPrivateRoutes.has(page) && !getUser()) { view.afterLogin = path; app.innerHTML = authPage(); }
    else if (page === 'login') { app.innerHTML = authPage(); }
    else {
        const bodies = { home, shop, stores:storeDirectory, product:() => productPage(parameter), store:() => storePage(parameter),
            cart:cartPage, checkout:checkoutPage, orders:ordersPage, account:accountPage, notifications:notificationsPage,
            compare:() => comparePage(parameter), messages:chatPage,
            help:() => unavailablePanel('SaleSync Help Centre', 'Browse products, manage your cart and addresses, and track verified payment and order statuses in My Purchases.'),
            payment:paymentPanel, result:paymentPanel };
        const body = bodies[page] ? bodies[page]() : unavailablePanel('Page not found', 'Open Home, Shop or My Account to continue.');
        app.innerHTML = header() + `<main class="wrap market-body">${body}</main>` + footer() + mobileTabs(path) + compareTray();
    }
    document.title = 'SaleSync Online · ' + (page === 'account' ? 'My Account' : page[0].toUpperCase() + page.slice(1));
    const registrationForm = app.querySelector('#auth-form');
    if (registrationForm && view.authTab === 'Register') {
        mountRegistration(registrationForm);
    }
    const favoritesCount = S.wishlist.filter(item => item.userId === userId).length;
    app.querySelector('.header-actions')?.insertAdjacentHTML('afterbegin',
        `<button class="icon-btn" data-action="favorites" aria-label="Favorites" title="Favorites" ${page === 'account' && view.accountTab === 'Saved' ? 'aria-current="page"' : ''}>${uiIcon('heart')}${favoritesCount ? `<span class="count-bubble">${favoritesCount}</span>` : ''}</button>`);
    app.querySelectorAll('a[href^="customer.html"]').forEach(link => { link.href = '#/home'; });
    app.querySelectorAll('[data-action]').forEach(button => {
        if (window.customerDisabledActions?.has(button.dataset.action)) {
            button.disabled = true;
            button.title = 'This feature is not available in the connected customer module yet.';
        }
    });
    app.querySelectorAll('[data-action="wishlist"]').forEach(button=>{
        const saved=S.wishlist.some(w=>w.productId===button.dataset.id);
        button.setAttribute('aria-pressed',String(saved));button.title=saved?'Remove from favorites':'Add to favorites';
        if(button.closest('.detail-info'))button.innerHTML=uiIcon('heart')+(saved?' Favorited · Remove':' Favorite');
    });
    if (page === 'account') {
        const content=app.querySelector('.account-content');
        const favoritesTab=app.querySelector('[data-action="account-tab"][data-tab="Saved"]');
        if (favoritesTab) favoritesTab.textContent='Favorites';
        if(view.accountTab==='Preferences') content?.insertAdjacentHTML('beforeend','<p class="small muted">These switches control optional email notices. Your in-app inbox keeps order and security records. Security email notices remain enabled.</p>');
        if(view.accountTab==='Saved') {
            const heading=content?.querySelector('h2');if(heading)heading.textContent='Favorites';
            content?.insertAdjacentHTML('beforeend',`<div class="top-gap">${(S.savedRows||[]).map(r=>`<article class="simple-row"><span>${esc(r.name)}${r.available?'':' · Currently unavailable'}</span>${btn('saved-remove','Remove',`data-id="${r.product_id}"`,'ghost sm')}</article>`).join('')}</div>`);
        }
        if(view.accountTab==='Seller Application'&&S.applications?.length) {
            const latest=S.applications[0];
            content.innerHTML=`<h2>Sell on SaleSync</h2>${S.applications.map(a=>`<article class="address-card"><div><h3>${esc(a.store_name)}</h3>${badge(a.status.replaceAll('_',' '))}<p>Application #${a.application_id} · ${dateTime(a.submitted_at)}</p><div class="timeline">${a.events.map(e=>`<div class="event"><b>${esc(e.status)}</b><p>${esc(e.message)} · ${dateTime(e.created_at)}</p></div>`).join('')}</div></div></article>`).join('')}${['rejected','needs_information'].includes(latest.status)?btn('apply-seller',latest.status==='needs_information'?'Update application':'Apply again','','primary'):''}`;
        }
        if (!getUser()?.verified) {
            const notice = document.createElement('div'); notice.className = 'notice row between flexwrap';
            notice.innerHTML = '<span>Verify your email before checkout. Use the link in your verification email.</span>' + btn('resend-verification','Resend verification','','soft sm');
            app.querySelector('main')?.prepend(notice);
        }
    }
    // Display only controls backed by this integration; provider chooses the payment method.
    if (page === 'checkout') {
        const methods = app.querySelector('.payment-methods');
        if (methods) methods.innerHTML = '<span class="badge info">PayMongo secure checkout</span>';
        const action = app.querySelector('[data-action="place-order"]');
        if (action) { action.disabled = !getUser()?.verified || !checkoutAddress() || view.busy; action.title = !getUser()?.verified ? 'Verify your email in My Account first.' : ''; }
    }
    if(page==='messages') {
        const chatForm=document.getElementById('chat-form');
        if (chatForm) chatForm.elements.text.value=view.chatDrafts?.[chatForm.dataset.thread] || '';
        const current=S.chats.find(t=>t.id===view.chat)||(!view.messageUnread?S.chats[0]:null);
        if(current?.hasOlder) app.querySelector('.chat-messages')?.insertAdjacentHTML('afterbegin',btn('chat-older','Load older messages',`data-id="${current.id}"`,'ghost sm'));
    }
    if(page==='orders') {
        app.querySelectorAll('.order-card').forEach(card=>{
            const order=getO(card.querySelector('.order-card-head b')?.textContent);
            if(order?.returnEligible&&!card.querySelector('[data-action="return"]'))card.querySelector('.order-foot .row')?.insertAdjacentHTML('beforeend',btn('return','Return / refund',`data-id="${order.id}"`,'ghost sm'));
        });
    }
    app.querySelectorAll('#auth-form input[name="password"],#auth-form input[name="confirmPassword"]').forEach(input => { input.minLength = view.authTab === 'Register' ? 10 : 1; });
    // Paid cancellation/refunds belong to future workflows; never offer a fake success.
    app.querySelectorAll('[data-action="cancel-order"]').forEach(button => {
        const order = getO(button.dataset.id);
        button.hidden = order?.status !== 'pending_payment' || !['pending'].includes(order?.rawPayment?.status) || Boolean(order?.rawPayment?.checkout_url);
    });
    app.querySelectorAll('[data-action="retry-pay"]').forEach(button => {
        if (!S.paymentConfigured) { button.disabled = true; button.title = 'Payment setup is pending.'; }
        if (['creating','unknown'].includes(getO(button.dataset.id)?.rawPayment?.status)) { button.disabled = true; button.textContent = 'Payment pending review'; button.title = 'The server must reconcile this payment before another attempt.'; }
    });
    const pendingCheckout = userId && sessionStorage.getItem(`salesync-checkout:${userId}`);
    if (pendingCheckout && ['cart','orders','checkout'].includes(page)) {
        const notice = document.createElement('div'); notice.className = 'notice row between flexwrap';
        notice.innerHTML = '<span>A previous checkout needs confirmation before you start another purchase.</span>' + btn('recover-checkout','Confirm previous checkout','','soft sm');
        app.querySelector('main')?.prepend(notice);
    }
    document.body.classList.toggle('has-compare', Boolean(compareIds().length));
    document.body.classList.toggle('product-route', page === 'product');
    const tray = app.querySelector('.compare-tray');
    document.body.style.setProperty('--compare-space', tray ? `${Math.ceil(tray.getBoundingClientRect().height)}px` : '0px');
    view.renderedRoute=path;
}
function paymentPanel() {
    const order = getO(route().split('/')[1]);
    if (!order) return unavailablePanel('Payment status', 'Open My Purchases to view your order.');
    return `<section class="panel" style="max-width:560px;margin:25px auto"><h1 class="h2">Payment ${esc(order.paymentStatus)}</h1>${kv('Order',esc(order.id))}${kv('Total',money(order.amount))}<p>Your payment status is confirmed by the server.</p>${btn('refresh-payment','Refresh status',`data-id="${order.id}"`,'ghost')}${order.status==='pending_payment'?btn('retry-pay','Continue to payment',`data-id="${order.id}"`,'primary'):''}${btn('route','My Purchases','data-to="orders"','ghost')}</section>`;
}
