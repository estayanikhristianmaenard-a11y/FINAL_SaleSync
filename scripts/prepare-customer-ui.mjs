// Import only customer presentation files and catalogue fixtures from the UI handoff.
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = path.resolve(process.argv[2] || path.join(root, 'storage/ui-handoff/extracted/SaleSync_Online_v4'));
const read = name => fs.readFileSync(path.join(source, name), 'utf8');
const context = vm.createContext({ window: {}, localStorage: { getItem: () => null, setItem: () => {} } });
for (const name of ['catalog', 'product-photos', 'product-options', 'catalog-refresh', 'brands', 'product-vectors', 'storefront-model', 'seed', 'commerce-model']) {
    vm.runInContext(read(`core/${name}.js`), context, { timeout: 5000 });
}
const data = JSON.parse(vm.runInContext(`JSON.stringify((()=>{
    const state=seed(); upgradeCatalog(state,state); upgradeBrandCatalog(state); upgradeWorkspaceData(state);
    const stores=state.stores.filter(s=>s.status==='Approved');
    const products=state.products.filter(p=>p.published&&!p.hidden&&!p.archivedDuplicate&&stores.some(s=>s.id===p.storeId));
    return {stores,products,banners:state.banners};
})())`, context, { timeout: 5000 }));
// No prototype users, passwords, orders, reviews, sessions or payment events are imported.
fs.mkdirSync(path.join(root, 'database/fixtures'), { recursive: true });
fs.writeFileSync(path.join(root, 'database/fixtures/customer-ui-catalog.json'), JSON.stringify(data, null, 2) + '\n');
const target = path.join(root, 'public/assets/ui');
const files = ['core/tokens.css', 'core/base.css', 'core/workspace.css', 'core/experience.css',
    'core/ui-kit.js', 'core/selectors.js', 'core/storefront-model.js', 'core/experience.js',
    'core/catalog.js', 'core/product-photos.js', 'core/product-vectors.js', 'core/brands.js',
    'customer/customer.css', 'customer/storefront.css', 'customer/shop/shop.js',
    'customer/shop/storefront.js', 'customer/shop/compare.js', 'customer/account/account.js'];
for (const file of files) {
    const destination = path.join(target, file);
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    fs.copyFileSync(path.join(source, file), destination);
}
// Keep the supplied components/styles; replace prototype-only promotional claims.
const shopPath = path.join(target, 'customer/shop/shop.js');
fs.writeFileSync(shopPath, fs.readFileSync(shopPath, 'utf8')
    .replace('Offline prototype · Synthetic inventory and simulated payments.', 'Customer storefront · Payments confirmed through PayMongo Test Mode.')
    .replace('Browse independent sample stores and compare everyday products.', 'Browse independent stores and compare everyday products.')
    .replace('⚡ Flash Sale', 'Featured products')
    .replace('<div class="countdown" id="flash-timer">Ends in ${flashTimer()}</div>', '<div class="countdown">Available now</div>')
    .replace('S.products.filter(p=>p.flash&&visible(p)).slice(0,5)', 'S.products.filter(visible).slice(0,5)')
    .replace('Limited offer · ${available(p.id)} left', '${available(p.id)} available')
    .replace('.map(voucherBox).join(\'\')}</div>${section(\'Featured Stores\'', '.map(voucherBox).join(\'\')||\'<div class="panel empty">No vouchers are currently available.</div>\'}</div>${section(\'Featured Stores\''));
const uiKitPath = path.join(target, 'core/ui-kit.js');
fs.writeFileSync(uiKitPath, fs.readFileSync(uiKitPath, 'utf8').replace('src="${esc(photo.src)}"', 'src="${esc(resolveImage(photo.src))}"'));
for (const file of ['core/experience.js', 'customer/shop/storefront.js']) {
    const destination = path.join(target, file);
    fs.writeFileSync(destination, fs.readFileSync(destination, 'utf8')
        .replaceAll('${esc(addr.line)}, ${esc(addr.zip)}', '${esc(addr.line)}${addr.zip?\', \'+esc(addr.zip):\'\'}')
        .replaceAll('${esc(a.line)}, ${esc(a.zip)}', '${esc(a.line)}${a.zip?\', \'+esc(a.zip):\'\'}')
        .replace('Review your address, options, pickup location and total before continuing.', 'Review your address, options and total before continuing.'));
}
const icons = read('core/workspaces.js').split('\n').find(line => line.startsWith('function workspaceIcon('));
const categories = read('core/commerce-model.js').split('\n').find(line => line.startsWith('const COMMERCE_CATEGORIES='));
fs.writeFileSync(path.join(target, 'core/customer-helpers.js'), '// Customer icons and taxonomy preserved from OVERALL-UI.\n' + icons + '\n' + categories + '\n');
fs.cpSync(path.join(source, 'assets'), path.join(target, 'assets'), { recursive: true });
fs.mkdirSync(path.join(root, 'docs/ui-handoff'), { recursive: true });
for (const name of ['IMAGE_CREDITS.md', 'BACKEND_HANDOFF.md', 'CONTRACT.md']) {
    fs.copyFileSync(path.join(source, 'docs', name), path.join(root, 'docs/ui-handoff', name));
}
console.log(`Prepared customer presentation, ${data.products.length} catalogue fixtures and ${data.stores.length} stores.`);
