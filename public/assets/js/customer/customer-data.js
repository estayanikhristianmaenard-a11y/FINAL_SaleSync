import { api } from './api.js';

const stockKey = item => `${item.productId}:${item.variantId}`;
export const selection = () => selectedCart().map(item => ({ product_id:getP(item.productId).backendId, variant_id:item.variantId }));

async function pages(endpoint) {
    const items = [];
    for (let page = 1; ; page++) {
        const result = await api(endpoint, { query:{ page, limit:100 } });
        items.push(...result.items);
        if (!result.items.length || items.length >= result.total) return items;
    }
}

function storeModel(row) {
    const display = row.presentation || {};
    const identity = display.identity || {};
    return { id:display.ui_key || `store-${row.seller_id}`, backendId:row.seller_id, name:row.store_name, description:row.description,
        category:display.category || 'Accessories', status:'Approved', brand:display.brand || '', brandReference:Boolean(display.brandReference),
        badge:display.badge || '', location:display.location || '', pickup:{ enabled:false },
        identity:{ ...identity, tone:/^#[a-f0-9]{6}$/i.test(identity.tone) ? identity.tone : '#344b7b',
            accent:/^#[a-f0-9]{6}$/i.test(identity.accent) ? identity.accent : '#bdcde8', tagline:identity.tagline || row.description } };
}

function productModel(row) {
    const display = row.presentation || {};
    return { id:display.ui_key || `product-${row.product_id}`, backendId:row.product_id, name:row.name, description:row.description,
        storeId:S.stores.find(store => store.backendId === row.seller_id)?.id, sku:row.sku, category:row.category_name || 'Accessories',
        price:row.price/100, listPrice:row.price/100, stock:row.stock_qty, published:true, hidden:false,
        type:display.type || 'pouch', brand:display.brand || '', brandReference:Boolean(display.brandReference),
        specs:display.specs || {}, images:display.images || [], productPhotoId:display.productPhotoId,
        variants:(row.variants || []).map(variant => ({ id:variant.variant_id, name:variant.name, attributes:variant.attributes, add:variant.price_delta/100, imageIndex:0 })),
        reviewSeed:0, ratingSeed:0, flash:false };
}

function ensureProduct(item) {
    let product = S.products.find(product => product.backendId === item.product_id);
    let store = S.stores.find(store => store.backendId === item.seller_id);
    if (!store) { store = storeModel({ seller_id:item.seller_id, store_name:item.store_name || 'Store', description:'' }); S.stores.push(store); }
    if (!product) {
        product = productModel({ ...item, name:item.name || item.product_name_snapshot, category_name:'Accessories', price:item.price || item.unit_price,
            stock_qty:item.stock_qty || 0, variants:[{ variant_id:item.variant_id, name:item.variant_name || item.variant_name_snapshot, price_delta:0, attributes:{} }] });
        product.published = false;
        S.products.push(product);
    }
    return product;
}

export function applyCart(cart) {
    const previous = new Map(S.cart.map(item => [stockKey(item), item.selected]));
    S.cart = cart.items.map(item => {
        const product = ensureProduct(item);
        product.stock = item.stock_qty;
        const option = product.variants.find(variant => variant.id === item.variant_id);
        if (option) product.price = product.listPrice = item.price/100 - option.add;
        return { userId, productId:product.id, variantId:item.variant_id, qty:item.quantity, price:item.price/100,
            selected:previous.get(`${product.id}:${item.variant_id}`) !== false };
    });
    view.quote = null;
}

function mapOrder(row) {
    const paymentStatus = row.payment.status === 'paid' ? 'Paid' : row.status === 'cancelled' ? 'Cancelled' : 'Pending';
    const statuses = { pending_payment:'Awaiting Payment', processing:'Processing', ready_for_shipment:'Ready for Shipment', shipped:'Shipped', delivered:'Delivered', completed:'Completed', cancelled:'Cancelled' };
    const items = row.items.map(item => {
        const product = ensureProduct(item);
        return { id:product.id, name:item.product_name_snapshot, sku:product.sku || '', storeId:product.storeId, qty:item.quantity,
            price:item.unit_price/100, orderItemId:item.order_item_id, discount:item.discount_amount/100, variantId:item.variant_id, variantName:item.variant_name_snapshot };
    });
    const storeIds = [...new Set(items.map(item => item.storeId))];
    const shipments = storeIds.map((storeId, index) => ({ id:`${row.order_id}-${storeId}`, storeId, status:statuses[row.status], method:'delivery',
        shipping:(Math.floor(row.shipping_fee/storeIds.length) + (index < row.shipping_fee%storeIds.length ? 1 : 0))/100,
        shippingDiscount:(Math.floor(row.shipping_discount/storeIds.length)+(index<row.shipping_discount%storeIds.length?1:0))/100,
        history:row.history.map(event => ({ status:statuses[event.status], time:event.created_at })) }));
    const delivered=row.history.findLast(e=>['completed','delivered'].includes(e.status));
    return { id:row.order_number, backendId:row.order_id, customerId:userId, created:row.created_at, items, shipments, status:row.status,
        returnEligible:paymentStatus==='Paid'&&delivered&&Date.now()-new Date(delivered.created_at).getTime()<=S.settings.returnDays*86400000,
        paymentStatus, rawPayment:row.payment, amount:row.total/100, shipping:row.shipping_fee/100, itemTotal:row.subtotal/100,
        recipientName:row.address.recipient_name, recipientPhone:row.address.phone, address:row.address.address_text,
        completed:row.status === 'completed', cancelled:row.status === 'cancelled', discount:row.merchandise_discount/100,
        shippingDiscount:row.shipping_discount/100, refundAmount:0, shopDiscounts:{}, platformAllocation:{} };
}

export async function loadConversations() {
    const threads=await api('/conversations');
    S.chats=await Promise.all(threads.map(async row=> {
        const detail=await api(`/conversations/${row.thread_id}`);
        let store=S.stores.find(s=>s.backendId===row.seller_id);
        if (!store) { store=storeModel({seller_id:row.seller_id,store_name:row.store_name,description:''}); S.stores.push(store); }
        return { id:String(row.thread_id),customerId:userId,storeId:store.id,hasOlder:detail.has_older,
            messages:detail.messages.map(m=>({id:m.message_id,by:String(m.sender_id),text:m.body,time:m.created_at,read:Boolean(m.read_at)})) };
    }));
}

export const attachmentUrl=id=>new URL(`../../../api/attachment.php?id=${Number(id)}`,import.meta.url).href;

export async function loadPrivate() {
    if (!userId) { for (const key of ['cart','orders','payments','notifications','wishlist','vouchers','claims','chats','returns','applications','savedRows']) S[key]=[]; return; }
    const [profile, addresses, cart, orderRows, notices, preferences, saved, vouchers, returns, applications] = await Promise.all([
        api('/profile'), api('/addresses'), api('/cart'), pages('/orders'), pages('/notifications'),api('/preferences'),api('/saved-products'),api('/vouchers'),api('/returns'),api('/seller-applications')]);
    S.users = [{ id:String(profile.user_id), backendId:profile.user_id, name:profile.name, email:profile.email,
        phone:profile.phone || '', birthDate:profile.birth_date || '',
        role:'Customer', status:'Active', verified:Boolean(profile.email_verified_at), notificationPrefs:preferences,
        addresses:addresses.map(address => ({ id:String(address.address_id), label:address.label, name:address.recipient_name,
            phone:address.phone, line:address.address_text, zip:'', default:Boolean(address.is_default),
            house_number:address.house_number || '', street:address.street || '', province_code:address.province_code || '',
            city_code:address.city_code || '', barangay_code:address.barangay_code || '', postal_code:address.postal_code || '' })) }];
    applyCart(cart);
    const details = await Promise.all(orderRows.map(order => api(`/orders/${order.order_id}`)));
    S.orders = details.map(mapOrder).reverse();
    S.savedRows=saved;
    S.wishlist=saved.map(row=>({userId,productId:ensureProduct(row).id}));
    S.vouchers=vouchers.map(v=>({id:String(v.voucher_id),name:v.name,code:v.code,scope:v.scope,kind:v.kind,
        value:v.kind==='fixed'?v.value/100:v.value,min:v.minimum_spend/100,max:v.max_discount/100,
        expires:v.expires_at,active:Boolean(v.active)&&v.starts_at<=now()&&v.expires_at>now(),storeId:S.stores.find(s=>s.backendId===v.seller_id)?.id}));
    S.claims=vouchers.filter(v=>v.claim_id).map(v=>({userId,voucherId:String(v.voucher_id),usedOrderId:v.used_order_id}));
    S.returns=returns.map(r=>({...r,id:String(r.case_id),orderId:r.order_number,itemId:ensureProduct({...r,name:r.product_name_snapshot}).id,
        qty:r.quantity,reason:r.reason,notes:r.explanation,status:r.status.split('_').map(s=>s[0].toUpperCase()+s.slice(1)).join(' '),created:r.created_at}));
    S.applications=applications;
    await loadConversations();
    S.payments = S.orders.map(order => ({ orderId:order.id, status:order.paymentStatus, attempts:[{ method:'PayMongo', status:order.paymentStatus }] }));
    S.notifications = notices.map(notice => ({ id:String(notice.notification_id), userId, text:notice.message,
        title:notice.title, type:notice.type || 'Account', context:notice.context_type, contextId:notice.context_id,
        read:Boolean(notice.read_at), created:notice.created_at }));
}

export async function refresh({ catalogue = true } = {}) {
    const session = await api('/session');
    userId = session.user?.role === 'customer' && session.user?.status === 'active' ? String(session.user.user_id) : '';
    S.paymentConfigured = session.payment_configured;
    S.minimumCustomerAge = session.minimum_customer_age;
    S.registrationDate = session.registration_date;
    S.settings.shipping = session.shipping_fee/100;
    S.settings.returnDays=session.return_request_days;
    if (catalogue) {
        const [stores, products, categories] = await Promise.all([api('/stores'), pages('/products'), api('/categories')]);
        S.stores = stores.map(storeModel);
        S.products = products.map(productModel).sort((a,b) => a.backendId-b.backendId);
        const categoryOrder = ['Electronics','Fashion','Accessories','Home','Beauty','Sports','Groceries','Toys'];
        CATS.splice(0, CATS.length, ...categories.filter(category => S.products.some(product => product.category === category.category_name))
            .sort((a,b) => (categoryOrder.indexOf(a.category_name)+1 || 99)-(categoryOrder.indexOf(b.category_name)+1 || 99))
            .map(category => [category.category_name, '']));
        S.storeCategories = [];
        for (const product of S.products) {
            const group = categoryGroup(product);
            product.storeCategoryId = `${product.storeId}-${categorySlug(group)}`;
            if (!S.storeCategories.some(category => category.id === product.storeCategoryId)) {
                S.storeCategories.push({ id:product.storeCategoryId, storeId:product.storeId, name:group, order:S.storeCategories.length });
            }
        }
    }
    if (!userId) S.users = [];
    await loadPrivate();
    view.loading = false;
    view.error = '';
    return session;
}
