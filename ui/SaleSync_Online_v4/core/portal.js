/* Owner: Core team | Purpose: globally registered portal boundaries, navigation and independent sessions. | Reads: location, localStorage | Writes: session keys | Emits: auth and compare events | Listens: storage */
'use strict';
const SaleSync=window.SaleSync={config:window.SaleSyncConfig||{portal:'customer',bundle:false},modules:{},router:{registry:{},register(portal,entry){(this.registry[portal]??={})[entry.id]=entry;},get(portal,id){return this.registry[portal]?.[id]},go(path){go(path)}},store:{},selectors:{},actions:{},auth:{}};
SaleSync.activePortal=()=>{
if(!SaleSync.config.bundle)return SaleSync.config.portal;
let m=location.hash.match(/^#\/portal\/(customer|seller|management|maintenance)(?:\/|$)/);
return m?.[1]||'customer';};
SaleSync.auth.key=()=>`salesync-session:${SaleSync.activePortal()}`;
SaleSync.auth.readUser=()=>{try{return JSON.parse(localStorage.getItem(SaleSync.auth.key())||'null')?.userId||''}catch{return ''}};
SaleSync.auth.writeSession=(uid)=>{localStorage.setItem(SaleSync.auth.key(),JSON.stringify({userId:uid,lastActive:Date.now()}))};
SaleSync.auth.touch=()=>{let key=SaleSync.auth.key();try{let s=JSON.parse(localStorage.getItem(key));if(s?.userId && Date.now()-(s.lastActive||0)>60000){s.lastActive=Date.now();localStorage.setItem(key,JSON.stringify(s))}}catch{}};
SaleSync.auth.rolePortal=u=>u?.role==='Management Administrator'?'management':u?.role==='Maintenance Administrator'?'maintenance':u?.role==='Seller'?'seller':'customer';
SaleSync.auth.roleAllowed=(u,portal)=>!!u&&u.status==='Active'&&(portal==='customer'?u.role==='Customer'||u.role==='Seller':portal==='seller'?u.role==='Seller'||u.role==='Customer'&&typeof S!=='undefined'&&S.stores.some(s=>s.ownerUserId===u.id&&['Approved','Suspended'].includes(s.status)):portal==='management'?u.role==='Management Administrator':u.role==='Maintenance Administrator');
SaleSync.auth.signOut=(reason='manual')=>{
const portal=SaleSync.activePortal(),oldUser=typeof userId!=='undefined'?userId:'';
const dialog=document.querySelector('#dialog');if(dialog?.open)dialog.close();
document.querySelector('#header-pop')?.replaceChildren();document.querySelector('#search-suggestions')?.replaceChildren();document.querySelector('#console-side')?.classList.remove('open');document.querySelector('#mask')?.classList.remove('active');
localStorage.removeItem(SaleSync.auth.key());userId='';
view={hero:0,category:'All',sort:'Recommended',search:'',priceMax:0,rating:0,inStock:false,shop:'',product:'p1',variant:'std',qty:1,gallery:0,productTab:'Description',orderTab:'All',accountTab:'Profile',adminTab:'Overview',returnTab:'All',chat:'',checkoutVoucher:'',shopVoucher:{},shippingMethod:'Standard',paymentMethod:'Card',selected:new Set(),modalSubmit:null,modalData:null,role:'',afterLogin:'',page:'',savedSubtab:'Products',diffOnly:false,compareCollapsed:false};
if(typeof S!=='undefined'&&oldUser){S.securityLog.unshift({id:id('SEC'),time:now(),event:'Signed out ('+portal+', '+reason+')'});S.securityLog=S.securityLog.slice(0,250);save();}
const dest=portal==='customer'?'home':'login';go(dest);render();toast('Signed out');};
