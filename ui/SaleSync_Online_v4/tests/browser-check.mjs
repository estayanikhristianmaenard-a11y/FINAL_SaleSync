import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const base=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
process.chdir(base);
const suitePath=path.join(base,'tests','browser-suite.js');
const server=http.createServer((req,res)=>{let f=path.resolve(base,'.'+decodeURIComponent(req.url.split('?')[0]));if(!f.startsWith(base+path.sep)){res.writeHead(403);res.end();return}try{res.setHeader('Content-Type',f.endsWith('.js')?'text/javascript':f.endsWith('.css')?'text/css':f.endsWith('.webp')?'image/webp':'text/html');res.end(fs.readFileSync(f))}catch{res.writeHead(404);res.end()}});
await new Promise((resolve,reject)=>{server.once('error',e=>e.code==='EADDRINUSE'?resolve():reject(e));server.listen(8000,'127.0.0.1',resolve)});
const tabs=await (await fetch('http://127.0.0.1:9222/json/list')).json();
const ws=new WebSocket(tabs[0].webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r,{once:true}));
let seq=0,pending=new Map(),errors=[];
ws.onmessage=e=>{let m=JSON.parse(e.data);if(m.id){pending.get(m.id)?.(m);pending.delete(m.id)}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text+': '+m.params.exceptionDetails.exception?.description)};
const send=(method,params={})=>new Promise(resolve=>{let id=++seq;pending.set(id,resolve);ws.send(JSON.stringify({id,method,params}))});
const evaluate=async expression=>{let r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.result.exceptionDetails)throw Error(JSON.stringify(r.result.exceptionDetails));return r.result.result.value};
await send('Runtime.enable');await send('Page.enable');
await send('Page.navigate',{url:'http://127.0.0.1:8000/SaleSync_Online_ALL.html'});
await new Promise(r=>setTimeout(r,1500));
if(process.argv[2]==='baseline'){
 const results={};
 results.ready=await evaluate('SaleSync.ready');
 results.buyNow=await evaluate(`(()=>{SaleSync.auth.writeSession('c1');userId='c1';S.cart=[{userId:'c1',productId:'p7',variantId:'std',qty:1,selected:true}];act('buy-now',{dataset:{id:'p7'}});return {selected:selectedCart().length,cart:cartFor()}})()`);
 results.fixture=await evaluate(`(()=>{cancelOrder('SS-FIX-2');return {held:held('p7'),issues:integrity()}})()`);
 await evaluate(`view.authTab='Register';act('portal-switch',{dataset:{portal:'seller'}})`);await new Promise(r=>setTimeout(r,200));
 results.portalAuth=await evaluate(`authSubmit({email:'seller.tech@salesync.test',password:'SaleSync2026!'}).then(()=> 'Signed in').catch(e=>e.message)`);
 results.errors=errors;fs.mkdirSync('docs',{recursive:true});fs.writeFileSync('docs/browser-baseline.json',JSON.stringify(results,null,2));console.log(JSON.stringify(results,null,2));
}else if(process.argv[2]==='inspect'){console.log(JSON.stringify(await evaluate(fs.readFileSync(process.argv[3],'utf8')),null,2))}
else if(process.argv[2]==='eval'){const result=await evaluate(fs.readFileSync(process.argv[3],'utf8'));fs.writeFileSync('docs/browser-regressions.json',JSON.stringify(result,null,2));console.log(JSON.stringify({passed:result.filter(r=>r.status==='PASS').length,failed:result.filter(r=>r.status==='FAIL')},null,2))}
else if(process.argv[2]==='verify'){
 const results=await evaluate(fs.readFileSync(suitePath,'utf8'));
 results.push(...await evaluate(fs.readFileSync(path.join(base,'tests','workspace-suite.js'),'utf8')));
 results.push(...await evaluate(fs.readFileSync(path.join(base,'tests','release-suite.js'),'utf8')));
 const pause=ms=>new Promise(r=>setTimeout(r,ms));
 for(const [portal,uid] of [['customer','c1'],['seller','s1'],['management','ma1'],['maintenance','it1']]){
  await send('Page.navigate',{url:'http://127.0.0.1:8000/'+portal+'.html'});await pause(500);
  await evaluate(`SaleSync.auth.writeSession('${uid}');userId='${uid}';go('${portal==='customer'?'home':portal+'/Dashboard'}')`);await pause(150);
  const pages=await evaluate(portal==='customer'?`['home','shop','stores','product/p1','store/brand-anker','compare/p1,p2','cart','orders','account','messages','notifications','help']`:`[...new Set(WORKSPACE_MODULES.${portal}.flatMap(m=>[m.name,...m.tabs.map(t=>t[1]),...(m.aliases||[])]))].map(p=>'${portal}/'+p).concat('${portal}/Notifications')`);
  for(const page of pages){const before=errors.length;await evaluate(`go(${JSON.stringify(page)})`);await pause(35);const state=await evaluate(`({title:!!app.querySelector('h1'),text:app.textContent.trim().length})`);results.push({name:portal+'.html '+page,status:state.title&&state.text>20&&errors.length===before?'PASS':'FAIL',...(errors.length>before?{errors:errors.slice(before)}:{})})}
 }
 await send('Page.navigate',{url:'http://127.0.0.1:8000/SaleSync_Online_ALL.html'});await pause(500);
 for(const width of [360,390,768,1024,1366,1920]){
  await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<=768});
  for(const [kind,uid,page] of [['seller','s1','Products'],['management','ma1','Product Moderation'],['maintenance','it1','System Overview']]){
   await evaluate(`localStorage.setItem('salesync-session:${kind}',JSON.stringify({userId:'${uid}',lastActive:Date.now()}));location.hash='#/portal/${kind}/${kind}/${page}'`);await pause(70);await evaluate('render()');
   const geometry=await evaluate(`(()=>{const side=$('.console-side'),toggle=$('.side-toggle');return {width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+2,sidebarWidth:Math.round(side.getBoundingClientRect().width),labeled:[...side.querySelectorAll('.nav-label')].every(el=>getComputedStyle(el).display!=='none'),mobileToggle:innerWidth>900||getComputedStyle(toggle).display!=='none',headings:app.querySelectorAll('.console-body h1').length,title:app.querySelector('.workspace-page-heading h1')?.textContent}})()`);
   results.push({name:kind+' unified workspace at '+width+'px',status:!geometry.overflow&&geometry.labeled&&geometry.mobileToggle&&geometry.headings===1&&geometry.title===page&&(width<=900||geometry.sidebarWidth===244)?'PASS':'FAIL',geometry});
   if(width===1366){const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('docs/'+kind+'-workspace-preview.png',Buffer.from(shot.result.data,'base64'))}
  }
  await evaluate(`localStorage.setItem('salesync-session:customer',JSON.stringify({userId:'c1',lastActive:Date.now()}));location.hash='#/portal/customer/store/brand-logitech'`);await pause(70);await evaluate(`act('shop-tab',{dataset:{id:'brand-logitech',tab:'All Products'}});window.scrollTo(0,0)`);
  const geometry=await evaluate(`({overflow:document.documentElement.scrollWidth>innerWidth+2,tabs:app.querySelectorAll('.store-navigation nav button').length,categoryRail:!!app.querySelector('.store-collection-rail'),brokenImages:[...app.querySelectorAll('.storefront img')].filter(i=>i.complete&&!i.naturalWidth).length})`);
  results.push({name:'Storefront scoped shopping at '+width+'px',status:!geometry.overflow&&geometry.tabs===4&&geometry.categoryRail&&!geometry.brokenImages?'PASS':'FAIL',geometry});
  if(width===1366||width===390){const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('docs/storefront-'+width+'-preview.png',Buffer.from(shot.result.data,'base64'))}
 }
 await send('Emulation.setDeviceMetricsOverride',{width:683,height:500,deviceScaleFactor:2,mobile:false});
 for(const [kind,uid,page] of [['seller','s1','Products'],['management','ma1','Product Moderation'],['maintenance','it1','System Overview'],['customer','c1','store/brand-logitech']]){
  await evaluate(`localStorage.setItem('salesync-session:${kind}',JSON.stringify({userId:'${uid}',lastActive:Date.now()}));location.hash='#/portal/${kind}/${kind==='customer'?'':kind+'/'}${page}'`);await pause(65);
  const overflow=await evaluate('document.documentElement.scrollWidth>innerWidth+2');results.push({name:kind+' 200% reflow equivalent (683 CSS px / 1366 physical px)',status:!overflow?'PASS':'FAIL'});
 }
 await evaluate(`localStorage.setItem('salesync-session:seller',JSON.stringify({userId:'s1',lastActive:Date.now()}));location.hash='#/portal/seller/seller/Products'`);await pause(70);await evaluate(`act('seller-edit',{dataset:{id:'p1'}})`);
 const modalGeometry=await evaluate(`({width:$('#dialog').getBoundingClientRect().width,viewport:innerWidth,overflow:$('#modal-fields').scrollWidth>$('#dialog').clientWidth+2})`);results.push({name:'Listing edit dialog at 200% reflow equivalent',status:modalGeometry.width<=modalGeometry.viewport&&!modalGeometry.overflow?'PASS':'FAIL',geometry:modalGeometry});await evaluate('closeModal()');
 await send('Page.navigate',{url:'http://127.0.0.1:8000/SaleSync_Online_ALL.html'});await pause(500);
 await evaluate(`SaleSync.auth.writeSession('c1');userId='c1';S.cart=[{userId:'c1',productId:'p7',variantId:'std',qty:1,selected:true}];S.compareLists.c1=['p1','p2','p3','p4'];go('cart')`);await pause(100);
 for(const width of [1440,768,640,390,320]){
  await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<=768});await evaluate('render();window.scrollTo(0,document.body.scrollHeight)');await pause(70);
  let geometry=await evaluate(`(()=>{let tray=$('.compare-tray').getBoundingClientRect(),checkout=$('[data-action="checkout"]').getBoundingClientRect(),tabs=$('.mobile-tabs').getBoundingClientRect();return {checkoutAboveTray:checkout.bottom<=tray.top+1,trayAboveTabs:innerWidth>768||tray.bottom<=tabs.top+1,width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+2,trayTop:tray.top,checkoutBottom:checkout.bottom}})()`);
  results.push({name:'Cart/compare layout at '+width+'px',status:geometry.checkoutAboveTray&&geometry.trayAboveTabs&&!geometry.overflow?'PASS':'FAIL',geometry});
  if(width===390){const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('docs/mobile-cart-preview.png',Buffer.from(shot.result.data,'base64'))}
  await evaluate(`go('product/p1')`);await pause(60);let productGeometry=await evaluate(`(()=>{const buy=$('.sticky-buy'),tray=$('.compare-tray');return {separate:innerWidth>768||buy.getBoundingClientRect().bottom<=tray.getBoundingClientRect().top+1,overflow:document.documentElement.scrollWidth>innerWidth+2}})()`);
  results.push({name:'Product/compare layout at '+width+'px',status:productGeometry.separate&&!productGeometry.overflow?'PASS':'FAIL',geometry:productGeometry});
  await evaluate(`go('cart')`);await pause(35);
 }
 await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await evaluate(`S.compareLists.c1=[];go('stores');window.scrollTo(0,0)`);await pause(250);
 const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync('docs/stores-desktop-preview.png',Buffer.from(shot.result.data,'base64'));

 // Review the expanded customer flows and print previews at phone, tablet and desktop widths.
 await evaluate(`S=migrate(seed());view.checkoutVoucher='';view.shippingVoucher='';view.shopVoucher={};view.deliveryChoices={};view.accountTab='Profile';view.messageQuery='';view.messageUnread=false;S.compareLists.c1=[];view.workspaceTabs={};`);
 for(const width of [320,390,768,1440]){
  await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<=768});
  const scenes=[['home','c1','home'],['account','c1','account'],['messages','c1','messages'],['login','','login'],['register','','login'],['checkout','c1','checkout'],['comparison','c1','compare'],['invoice','c1','orders']];
  for(const [scene,uid,route] of scenes){
   await evaluate(uid?`localStorage.setItem('salesync-session:customer',JSON.stringify({userId:'${uid}',lastActive:Date.now()}));userId='${uid}';`:`localStorage.removeItem('salesync-session:customer');userId='';`);
   if(scene==='checkout')await evaluate(`S.cart=[{userId:'c1',productId:'p7',variantId:'std',qty:1,selected:true},{userId:'c1',productId:'p8',variantId:'std',qty:1,selected:true}];view.deliveryChoices={tech:'pickup'};view.checkoutVoucher='';view.shippingVoucher='';view.shopVoucher={};view.buyNowItem=null;`);
   if(scene==='comparison')await evaluate(`S.compareLists.c1=['brand-logitech-2','brand-logitech-3']`);
   if(scene==='invoice')await evaluate(`S.compareLists.c1=[]`);
   await evaluate(`location.hash='#/portal/customer/${route}';`);await pause(90);
   if(scene==='login'||scene==='register')await evaluate(`view.authTab='${scene==='register'?'Register':'Sign in'}';render()`);
   else await evaluate('render();window.scrollTo(0,0)');
   if(scene==='invoice')await evaluate(`act('invoice',{dataset:{id:'SS-FIX-1'}})`);
   await pause(100);
   const state=await evaluate(`({overflow:document.documentElement.scrollWidth>innerWidth+2,broken:[...app.querySelectorAll('img')].filter(i=>i.complete&&!i.naturalWidth).length,retiredLabel:/\bd[e]mo\b/i.test(app.textContent),headings:app.querySelectorAll('h1').length,dialog:$('#dialog').open?$('#dialog').getBoundingClientRect().width<=innerWidth:true})`);
   results.push({name:scene+' revised flow at '+width+'px',status:!state.overflow&&!state.broken&&!state.retiredLabel&&state.headings>0&&state.dialog?'PASS':'FAIL',...state});
   if(width===390||width===1440){const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync('docs/'+scene+'-'+width+'-release.png',Buffer.from(shot.result.data,'base64'))}
   if(scene==='invoice')await evaluate('closeModal()');
  }
 }
 await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 const imageState=await evaluate(`Promise.all([...Object.values(CATALOG_IMAGES).map(i=>i.src),...Object.values(PRODUCT_PHOTOS).map(i=>i.src),...Object.values(PRODUCT_OPTION_ASSETS),...Object.values(PRODUCT_VECTORS).map(svg=>'data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg))].map(src=>new Promise(resolve=>{let i=new Image();i.onload=()=>resolve(true);i.onerror=()=>resolve(false);i.src=src}))).then(states=>({total:states.length,failed:states.filter(x=>!x).length}))`);results.push({name:'All local image assets decode in browser',status:!imageState.failed?'PASS':'FAIL',...imageState});
 await send('Page.navigate',{url:'file:///'+base.replaceAll('\\','/')+'/SaleSync_Online_ALL.html'});await pause(700);
 const offline=await evaluate(`({ready:SaleSync.ready,products:S.products.length,photo:CATALOG_IMAGES.headphones.src.startsWith('data:')&&Object.values(PRODUCT_PHOTOS).every(p=>p.src.startsWith('data:')),issues:integrity()})`);results.push({name:'Standalone file opens offline with embedded product photos',status:offline.ready&&offline.products===280&&offline.photo&&!offline.issues.length?'PASS':'FAIL',...offline});
 fs.writeFileSync('docs/browser-regressions.json',JSON.stringify(results,null,2));console.log(JSON.stringify({passed:results.filter(r=>r.status==='PASS').length,failed:results.filter(r=>r.status==='FAIL'),exceptions:errors},null,2));process.exitCode=results.some(r=>r.status==='FAIL')||errors.length?1:0;
}
else {console.log('Errors:',errors);console.log(await evaluate('({ready:SaleSync.ready,products:S.products.length,issues:integrity(),title:document.title})'));}
ws.close();
server.close();
