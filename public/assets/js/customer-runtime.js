/* Presentation state for the coworker's customer templates. Server records are authoritative. */
const $ = selector => document.querySelector(selector);
const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
const money = value => new Intl.NumberFormat('en-PH', { style:'currency', currency:'PHP' }).format(Number(value || 0));
const now = () => new Date().toISOString();
const date = value => value ? new Date(value).toLocaleDateString('en-PH') : '—';
const dateTime = value => value ? new Date(value).toLocaleString('en-PH') : '—';
const localDay = value => new Date(value).toISOString().slice(0, 10);
const pct = value => Math.max(0, Math.min(100, value));
const guard = (condition, message) => { if (!condition) throw new Error(message || 'This action is unavailable.'); };
const ART = {}, PALETTE = [['#eff3ff','#b7caff']];
const CATS = [];
let userId = '';
let S = { users:[], stores:[], products:[], cart:[], orders:[], payments:[], notifications:[], reviews:[], returns:[], chats:[],
    vouchers:[], claims:[], wishlist:[], following:[], views:[], storeCategories:[], settings:{ shipping:0, returnDays:0, pickupEnabled:false },
    banners:[{ title:'Big finds. Better everyday.', subtitle:'Unbox savings on tech and everyday essentials.', type:'headphones' },
        { title:'Refresh your personal style.', subtitle:'Shop expressive fashion from Filipino stores.', type:'jacket' },
        { title:'Make every space feel like home.', subtitle:'Discover useful home favourites in one place.', type:'lamp' }] };
let view = { hero:0, category:'All', sort:'Recommended', search:'', priceMax:0, rating:0, inStock:false, shop:'',
    product:'', variant:'std', qty:1, gallery:0, productTab:'Description', orderTab:'All', accountTab:'Profile',
    paymentMethod:'PayMongo', authTab:'Sign in', selected:new Set(), comparisons:[], savedSubtab:'Products',
    modalSubmit:null, modalBusy:false, quote:null, addressId:'', loading:true };
const getUser = () => S.users.find(user => user.id === userId);
const getP = id => S.products.find(product => product.id === String(id));
const getStore = id => S.stores.find(store => store.id === String(id));
const getO = id => S.orders.find(order => order.id === String(id) || String(order.backendId) === String(id));
const getPay = id => S.payments.find(payment => payment.orderId === id);
const hasSeller = () => false, ownedStore = () => null, isSellerView = () => false, isManagement = () => false;
const available = id => getP(id)?.stock || 0;
const optionPrice = (product, variantId) => product.price + (product.variants.find(variant => variant.id === variantId)?.add || 0);
const compareIds = () => view.comparisons.filter(id => getP(id));
const isCompared = id => compareIds().includes(id);
const save = () => {}; // Vendor view helpers may call this; never persist server records in localStorage.
const SaleSync = window.SaleSync = { ready:false, config:{ portal:'customer', bundle:false }, activePortal:() => 'customer',
    auth:{ readUser:() => userId }, selectors:{}, actions:{} };
function resolveImage(source) { return allowedImage(source) ? 'assets/ui/' + source : ''; }
function selectedCart() { return cartFor().filter(item => item.selected !== false); }
function cartSummary() { const items = selectedCart(); return { items, total:items.reduce((sum, item) => sum + item.price * item.qty, 0) }; }
function checkoutAddress() { return getUser()?.addresses.find(address => address.id === view.addressId) || getUser()?.addresses.find(address => address.default) || getUser()?.addresses[0]; }
function deliveryFor() { return 'delivery'; }
function categoryGroup(product) { return (COMMERCE_CATEGORIES[product.category] || []).find(group => group.slice(1).includes(product.type))?.[0] || product.category; }
function buildQuote() {
    const quote = view.quote;
    if (!quote) return { items:[], shops:{} };
    const items = quote.items.map(item => {
        const product = S.products.find(product => product.backendId === item.product_id);
        const option = product.variants.find(variant => variant.id === item.variant_id);
        if (option) product.price = product.listPrice = item.price/100 - option.add;
        return { productId:product.id, variantId:item.variant_id, qty:item.quantity };
    });
    const shops = {}, shopOff = {}, shippingByShop = {}, shippingDiscounts = {};
    for (const item of items) { const store = getP(item.productId).storeId; (shops[store] ??= []).push(item); }
    const storeIds = Object.keys(shops);
    storeIds.forEach((id, index) => { shopOff[id] = shippingDiscounts[id] = 0; shippingByShop[id] = (Math.floor(quote.shipping_fee / storeIds.length) + (index < quote.shipping_fee % storeIds.length ? 1 : 0)) / 100; });
    let discountShop=0,discountPlatform=0;
    for(const voucher of quote.applied_vouchers||[]) {
        if(voucher.scope==='shop'){const id=S.stores.find(s=>s.backendId===voucher.seller_id)?.id;shopOff[id]=(shopOff[id]||0)+voucher.discount/100;discountShop+=voucher.discount/100;}
        if(voucher.scope==='platform')discountPlatform+=voucher.discount/100;
    }
    storeIds.forEach((id,index)=>{shippingDiscounts[id]=(Math.floor((quote.shipping_discount||0)/storeIds.length)+(index<(quote.shipping_discount||0)%storeIds.length?1:0))/100;});
    return { items, shops, shopOff, shippingByShop, shippingDiscounts, total:quote.subtotal/100, shipping:quote.shipping_fee/100,
        discountShop, discountPlatform, discountShipping:(quote.shipping_discount||0)/100, due:quote.total/100 };
}
