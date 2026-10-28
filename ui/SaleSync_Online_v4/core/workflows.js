/* Shared, guarded workflows. External services remain local simulations. */
function validateBackup(raw){
 guard(raw&&[3,4].includes(raw.version),'Unsupported backup version');
 for(const key of ['users','products','stores','orders','payments','ledger'])guard(Array.isArray(raw[key]),'Backup is missing '+key);
 for(const key of ['users','products','stores','orders','payments']){const ids=raw[key].map(x=>x?.id);guard(ids.every(x=>typeof x==='string')&&new Set(ids).size===ids.length,'Invalid or duplicate IDs in '+key)}
 guard(raw.users.every(u=>['Customer','Seller','Management Administrator','Maintenance Administrator'].includes(u.role)&&typeof u.email==='string'&&Array.isArray(u.addresses)),'Invalid backup users');
 guard(raw.products.every(p=>Number.isFinite(p.price)&&p.price>0&&raw.stores.some(s=>s.id===p.storeId)&&Array.isArray(p.variants)),'Invalid backup products');
 guard(raw.ledger.every(l=>raw.products.some(p=>p.id===l.productId)&&Number.isInteger(l.qty)&&l.qty>=0&&['open','allocate','restock','adjust','reserve','release','commit','reconcile'].includes(l.kind)),'Invalid backup inventory');
 guard(raw.orders.every(o=>Array.isArray(o.items)&&Array.isArray(o.shipments)&&raw.payments.some(p=>p.orderId===o.id)&&Number.isFinite(o.amount)&&o.items.every(i=>raw.products.some(p=>p.id===i.id)&&Number.isInteger(i.qty)&&i.qty>0)&&o.shipments.every(sh=>raw.stores.some(s=>s.id===sh.storeId)&&Array.isArray(sh.history))),'Invalid backup orders');
 const defaults=seed();for(const [key,value] of Object.entries(defaults)){if(Array.isArray(value)&&raw[key]!==undefined)guard(Array.isArray(raw[key]),'Invalid backup collection: '+key)}
 guard(!raw.settings||Number.isFinite(raw.settings.shipping)&&Number.isFinite(raw.settings.commission)&&Number.isFinite(raw.settings.returnDays)&&Number.isFinite(raw.settings.autoCompleteDays),'Invalid backup settings');
 for(const key of ['marketCategories','storeCategories'])if(raw[key]!==undefined)guard(Array.isArray(raw[key])&&raw[key].every(c=>typeof c.id==='string'&&typeof c.name==='string'&&Number.isFinite(c.order)),'Invalid category collection: '+key);
 return raw;
}
function customerLogin(){if(!userId){view.afterLogin=route();go('login');return false}guard(SaleSync.activePortal()==='customer','Open the customer portal to shop');return true}
function buyNow(productId,variantId='std',qty=1){
 const p=getP(productId);guard(visible(p)&&Number.isInteger(qty)&&qty>0&&qty<=available(productId),'Product unavailable or insufficient stock');
 guard(p.variants.some(v=>v.id===variantId),'Choose a valid variant');
 view.buyNowItem={productId,variantId,qty};view.checkoutVoucher='';view.shopVoucher={};go('checkout');
}
function recordCompare(pid){
 S.compareUsers??={};const key=userId||'guest-browser';const users=S.compareUsers[pid]??=[];
 if(!users.includes(key))users.push(key);S.compareCounts[pid]=users.length;
}
function cancelPaidOrder(o,seller=false){
 guard(o&&['Paid','Partially Refunded'].includes(o.paymentStatus),'Only paid unshipped orders can be refunded');
 guard(seller?o.shipments.some(sh=>ownerOfStore(sh.storeId)===userId):o.customerId===userId,'Not your order');
 guard(o.shipments.every(sh=>['To Process','Processing','Ready for Shipment'].includes(sh.status)),'A parcel has shipped; use the return process after delivery');
 guard(!(o.refundAmount>0),'An order with an existing refund needs Management review');
 for(const i of o.items){ledger(i.id,'restock',i.qty,'Cancelled '+o.id);S.returns.push({id:id('RET'),orderId:o.id,itemId:i.id,qty:i.qty,reason:seller?'Seller out of stock':'Customer cancellation',notes:'Cancelled before shipment — synthetic refund',status:'Refunded',refund:Math.round(i.price*i.qty*(1-(o.discount||0)/o.itemTotal)*100)/100,restock:true,created:now(),history:[]})}
 o.shipments.forEach(sh=>{sh.status='Cancelled';sh.history.push({status:'Cancelled and refunded',time:now(),source:seller?'Seller':'Customer'})});
 o.paymentStatus=getPay(o.id).status='Refunded';o.refundAmount=o.amount;o.cancelled=true;
 getPay(o.id).events.push({id:id('RF'),event:'refund.cancelled',amount:o.amount,time:now()});
 S.payouts.filter(p=>p.orderId===o.id&&p.status!=='Adjustment').forEach(p=>{if(p.status==='Paid')S.payouts.push({id:id('POADJ'),orderId:o.id,storeId:p.storeId,status:'Adjustment',gross:0,commission:0,amount:-p.amount,refundAdjust:p.amount,created:now()});else{p.refundAdjust=(p.refundAdjust||0)+p.amount;p.amount=0;p.status='Cancelled'}});
 note(o.customerId,'Order '+o.id+' cancelled. Test refund: '+money(o.amount),'Order');audit('Paid order cancelled and refunded',o.id);commit('order.cancelled',{id:o.id});toast('Order cancelled; synthetic refund recorded');
}
function runHousekeeping(manual=false){
 let changed=false;const time=Date.now();for(const u of S.users)if(u.status==='Suspended'&&u.suspension?.until&&time>=new Date(u.suspension.until).getTime()){u.status='Active';u.suspension=null;changed=true;audit('Timed account suspension ended',u.id,'Scheduler')}
 for(const o of S.orders){
  const payment=getPay(o.id);
  if(payment&&o.paymentStatus==='Pending'&&time-new Date(payment.attempts.at(-1)?.created||o.created).getTime()>=(S.settings.orderExpiryMinutes||30)*60000){
   o.paymentStatus=payment.status='Expired';o.items.forEach(i=>ledger(i.id,'release',i.qty,o.id));o.shipments.forEach(sh=>{sh.status='Cancelled';sh.history.push({status:'Reservation expired',time:now(),source:'Scheduler'})});changed=true;
  }
  for(const sh of o.shipments){const delivered=sh.history.findLast(h=>h.status==='Delivered');if(sh.status==='Delivered'&&delivered&&!S.returns.some(r=>r.orderId===o.id&&getP(r.itemId)?.storeId===sh.storeId&&!['Refunded','Refund Denied'].includes(r.status))&&time-new Date(delivered.time)>=S.settings.autoCompleteDays*86400000){sh.status='Completed';sh.history.push({status:'Completed',time:now(),source:'Scheduler'});changed=true}}
  o.completed=o.shipments.every(sh=>sh.status==='Completed');
 }
 for(const p of S.products)if(p.flash&&p.flashEnds&&time>=new Date(p.flashEnds).getTime()){p.flash=false;p.price=p.regularPrice||p.listPrice;changed=true}
 for(const payout of S.payouts.filter(p=>p.status==='Accrued')){const o=getO(payout.orderId),sh=o?.shipments.find(s=>s.storeId===payout.storeId);if(sh?.status==='Completed'&&!S.returns.some(r=>r.orderId===o.id&&getP(r.itemId)?.storeId===sh.storeId&&!['Refunded','Refund Denied'].includes(r.status))){const last=sh.history.findLast(h=>['Delivered','Completed'].includes(h.status));if(last&&time-new Date(last.time)>=S.settings.autoCompleteDays*86400000){payout.status='Eligible';changed=true}}}
 for(const j of S.jobs.filter(j=>j.status==='Queued'&&(!j.nextTry||new Date(j.nextTry).getTime()<=time))){EmailMock.send(j);changed=true}
 S.jobs=S.jobs.slice(0,400);S.webhooks=S.webhooks.slice(0,500);
 if(changed||manual){log('Housekeeping completed: reservations, delivery, email, payouts and flash windows','Scheduler');save();if(SaleSync.ready||manual)render()}
 if(manual)toast('Housekeeping completed');
}
const courierTransitions={'Shipped':['Out for Delivery','Delivered','Delivery Attempt Failed','Return to Sender'],'Out for Delivery':['Delivered','Delivery Attempt Failed','Return to Sender'],'Delivery Attempt Failed':['Out for Delivery','Return to Sender']};
function syncCourierOptions(){const shipment=$('#sim-shipment'),select=$('#sim-event');if(!shipment||!select)return;const sh=orderOfShipment(shipment.value)?.shipments.find(s=>s.id===shipment.value);select.innerHTML=(courierTransitions[sh?.status]||[]).map(status=>`<option>${esc(status)}</option>`).join('');select.disabled=!select.options.length}
function storageUsage(){const bytes=new Blob([JSON.stringify(S)]).size;return `<div class="notice ${bytes>3500000?'warn':''}">Local data: ${(bytes/1024/1024).toFixed(2)} MB. ${bytes>3500000?'Storage is approaching typical browser limits. Export a backup.':'Jobs retain 400 records and webhooks retain 500. Browser storage limits vary.'}</div>`}
function recordPage(records,key,statusField){
 view.recordFilters??={};const f=view.recordFilters[key]||{},q=(f.search||'').trim().toLowerCase();
 const filtered=records.filter(r=>(!f.role||r.role===f.role)&&(!f.status||r[statusField]===f.status)&&(!q||JSON.stringify(r).toLowerCase().includes(q)));
 view.recordMeta??={};view.recordMeta[key]={count:filtered.length,statuses:[...new Set(records.map(r=>r[statusField]))].sort()};view.recordPages??={};
 const page=Math.max(0,Math.min(view.recordPages[key]||0,Math.ceil(filtered.length/20)-1));view.recordPages[key]=page;
 return filtered.slice(page*20,page*20+20);
}
function recordControls(key){const f=view.recordFilters?.[key]||{},m=view.recordMeta[key];return `<div class="record-controls"><label>Search <input class="input" type="search" data-record-filter="${key}" data-field="search" value="${esc(f.search||'')}" aria-label="Search ${key}" placeholder="Search records; Enter to apply"></label><label>Status <select class="input" data-record-filter="${key}" data-field="status"><option value="">All statuses</option>${m.statuses.map(s=>`<option ${s===f.status?'selected':''}>${esc(s)}</option>`).join('')}</select></label>${key==='users'?`<label>Account type<select class="input" data-record-filter="users" data-field="role"><option value="">All account types</option>${['Customer','Seller','Management Administrator','Maintenance Administrator'].map(role=>`<option ${f.role===role?'selected':''}>${esc(role)}</option>`).join('')}</select></label>`:''}<span>${m.count} records</span></div>`}
function recordPager(key){const n=view.recordMeta[key].count,page=view.recordPages[key]||0;return `<div class="record-pager">${btn('record-prev','Previous',`data-key="${key}" ${page===0?'disabled':''}`,'ghost sm')}<span>Page ${page+1} of ${Math.max(1,Math.ceil(n/20))}</span>${btn('record-next','Next',`data-key="${key}" ${(page+1)*20>=n?'disabled':''}`,'ghost sm')}</div>`}
