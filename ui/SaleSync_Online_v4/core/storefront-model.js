/* Store-owned taxonomy and authored SaleSync identities; no merchant affiliation. */
const STORE_COLLECTIONS={
 electronics:[['Audio','headphones','earbuds','speaker'],['Mice & keyboards','mouse','keyboard','deskpad'],['Charging & connectivity','powerbank','charger','wallcharger','router'],['Cameras & accessories','camera','webcam','phonecase','stand','watch']],
 fashion:[['Tops','tee','shirt'],['Outerwear','hoodie','jacket','sportjacket'],['Dresses & bottoms','dress','pants','shorts','leggings'],['Shoes & accessories','sneakers','sandals','cap','bag','backpack']],
 accessories:[['Eyewear','sunglasses'],['Jewelry','necklace','earrings','bracelet','ring'],['Bags & everyday carry','bag','backpack','wallet','pouch','bottle'],['Collectibles','blindbox','teddy']],
 home:[['Kitchen & dining','mug','jars','lunchbox','bottle','ricecooker','airfryer','fryingpan'],['Sleep & comfort','mattress','pillow','towel'],['Lighting & decor','lamp','plant','candle'],['Storage & workspace','storage','shelf','notebook']],
 beauty:[['Skin care','skincare','cleanser','serum'],['Makeup','lipstick','foundation','mascara','compact'],['Tools & essentials','brush','dryer','cottonpads']],
 sports:[['Training equipment','mat','dumbbell','roller'],['Footwear','sportsshoe','sneakers','sandals'],['Activewear','tee','leggings','shorts','sportjacket'],['Hydration & accessories','bottle','towel','watch']],
 groceries:[['Coffee & drinks','coffee'],['Snacks','snack']],toys:[['Build & play','bricks','robot','puzzle'],['Plush & collectibles','teddy','blindbox']]
};
const STORE_COLORS=[['#123c45','#e1b982'],['#723f43','#e8d4cb'],['#395e4c','#ded7b3'],['#684e7c','#ead0d8'],['#344b7b','#bdcde8'],['#7d502b','#edcf9e'],['#435661','#bbd8d2'],['#6d4760','#e2cbd6']];
function categorySlug(value){return String(value).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')||'collection'}
function categoryIdFor(name){return 'cat-'+categorySlug(name)}
function storeCollections(sid,includeArchived=false){return (S.storeCategories||[]).filter(c=>c.storeId===sid&&(includeArchived||!c.archived)).sort((a,b)=>a.order-b.order)}
function syncCategoryIndex(state){CATS.splice(0,CATS.length,...state.marketCategories.filter(c=>!c.archived).sort((a,b)=>a.order-b.order).map(c=>[c.name,c.icon||'']))}
function upgradeWorkspaceData(state){
 upgradeProductPhotography(state);
 if(typeof upgradeCommerceData==='function')upgradeCommerceData(state);
 state.marketCategories??=DEFAULT_CATS.map(([name,icon],order)=>({id:categoryIdFor(name),name,icon,order,archived:false,imageType:{Electronics:'headphones',Fashion:'tee',Accessories:'sunglasses',Home:'lamp',Beauty:'skincare',Sports:'mat',Groceries:'coffee',Toys:'teddy'}[name]}));
 state.storeCategories??=[];
 for(const [index,store] of state.stores.entries()){
  if(!store.identity){const colors=STORE_COLORS[index%STORE_COLORS.length];store.identity={tone:colors[0],accent:colors[1],tagline:{Electronics:'Useful technology. Everyday possibilities.',Fashion:'Find your everyday expression.',Accessories:'The details make it yours.',Home:'A little more comfort, every day.',Beauty:'Make room for your daily ritual.',Sports:'Made for your next move.',Groceries:'Stock your everyday favorites.',Toys:'More moments of discovery.'}[store.category]||'Discover something for your everyday.',coverType:state.products.find(p=>p.storeId===store.id)?.type||'lamp'}}
  const groups=STORE_COLLECTIONS[String(store.category||'Accessories').toLowerCase()]||[];
  for(const p of state.products.filter(p=>p.storeId===store.id)){
   if(!p.images?.length&&(CATALOG_IMAGES[p.type]||PRODUCT_VECTORS[p.type]))p.images=[{src:PRODUCT_VECTORS[p.type]?'assets/illustrations/'+p.type+'.svg':'assets/products/'+p.type+'.webp',alt:p.name+' — illustrative product image'}];
   p.categoryId??=categoryIdFor(p.category);
   const known=state.storeCategories.find(c=>c.id===p.storeCategoryId&&c.storeId===store.id&&!c.archived);
   if(known)continue;
   const group=groups.find(g=>g.slice(1).includes(p.type));const name=group?.[0]||p.category;
   let category=state.storeCategories.find(c=>c.storeId===store.id&&c.name===name&&!c.archived);
   if(!category){category={id:store.id+'-collection-'+categorySlug(name),storeId:store.id,name,order:state.storeCategories.filter(c=>c.storeId===store.id).length,archived:false};if(state.storeCategories.some(c=>c.id===category.id))category.id+='-'+categorySlug(p.id);state.storeCategories.push(category)}
   p.storeCategoryId=category.id;
  }
  if(!/^#[a-f0-9]{6}$/i.test(store.identity.tone))store.identity.tone=STORE_COLORS[index%STORE_COLORS.length][0];
  if(!/^#[a-f0-9]{6}$/i.test(store.identity.accent))store.identity.accent=STORE_COLORS[index%STORE_COLORS.length][1];
 }
 state.workspaceSchema=1;syncCategoryIndex(state);
}
function allowedImage(src){return typeof src==='string'&&src.length<900000&&(/^(assets\/(products\/[a-z0-9-]+\.webp|illustrations\/[a-z0-9-]+\.svg))$/.test(src)||/^data:image\/(webp|png|jpeg);base64,[A-Za-z0-9+/=]+$/.test(src))}
function productImageCount(p){return p.images?.filter(i=>allowedImage(i.src)).length||1}
function storeIdentityArt(store){const type=store.identity?.coverType||S.products.find(p=>p.storeId===store.id)?.type||'lamp';const product=S.products.find(p=>p.storeId===store.id&&p.type===type)||S.products.find(p=>p.storeId===store.id);return art(product||{id:'identity-'+store.id,type,name:store.name,category:store.category})}
function storeEmblem(store){if(store.identity?.logo&&allowedImage(store.identity.logo))return `<img class="store-emblem" src="${esc(resolveImage(store.identity.logo))}" alt="${esc(store.name)} collection logo">`;const category=String(store.category||'Accessories').toLowerCase(),shapes={electronics:'<path d="m25 5-10 16h12l-7 16 19-22H26l5-10Z"/>',fashion:'<path d="m14 12 9-5 9 5 9 8-7 7-5-5v17H16V22l-5 5-7-7Z"/>',accessories:'<path d="m9 15 7-9h18l7 9-16 24Zm0 0h32M16 6l9 33L34 6"/>',home:'<path d="m7 23 18-16 18 16M12 20v21h26V20M21 41V28h9v13"/>',beauty:'<path d="M25 40V19m0 5C10 25 9 11 11 9c12-1 16 7 14 15Zm0 7c0-12 11-18 17-15 0 10-7 18-17 15Z"/>',sports:'<circle cx="25" cy="25" r="18"/><path d="M7 25h36M25 7c-13 11-13 25 0 36m0-36c13 11 13 25 0 36"/>',groceries:'<path d="M12 16h28l-5 18H16L9 7H4M18 41h1m14 0h1"/>',toys:'<path d="m25 6 6 12 13 2-10 10 2 14-11-7-12 7 3-14L6 20l13-2Z"/>'};return `<svg class="store-emblem" viewBox="0 0 50 50" role="img" aria-label="${esc(store.name)} sample collection emblem" style="--store-tone:${store.identity?.tone||'#344b7b'}"><rect width="50" height="50" rx="14" fill="currentColor"/><g fill="none" stroke="white" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round">${shapes[category]||shapes.home}</g></svg>`}
