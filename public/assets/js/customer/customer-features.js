import { api } from './api.js';
import { loadPrivate, loadConversations, attachmentUrl, selection } from './customer-data.js';
import { passwordField, viewInvoice } from './customer-controls.js';

export const voucherIds=()=>[view.checkoutVoucher,view.shippingVoucher,...Object.values(view.shopVoucher||{})].filter(Boolean).map(Number);
export const quote=()=>api('/checkout/quote',{method:'POST',body:{items:selection(),voucher_ids:voucherIds()}});
const filesField=(purpose)=>`<label class="field"><span>${purpose==='return'?'Evidence images':'Business documents (images or PDF)'}</span><input class="input" name="attachments" type="file" multiple accept="${purpose==='return'?'image/jpeg,image/png,image/webp':'image/jpeg,image/png,image/webp,application/pdf'}"></label><p class="small muted">Up to five files, 2 MiB each. Attachments are private to your account.</p>`;

async function upload(purpose) {
    const files=[...document.querySelector('#modal-form [name="attachments"]').files];
    guard(files.length<=5,'Attach up to five files.');
    for (const file of files) guard(file.size<=2097152,'Each file must be 2 MiB or smaller.');
    const ids=[];
    for (const file of files) {
        const content=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result.split(',')[1]);reader.onerror=reject;reader.readAsDataURL(file);});
        const row=await api('/uploads',{method:'POST',body:{purpose,name:file.name,content}});
        ids.push(row.upload_id);
    }
    return ids;
}

export async function readChat(id) {
    if (!id) return;
    await api(`/conversations/${id}/read`,{method:'PATCH'});
    const chat=S.chats.find(c=>c.id===String(id));
    chat?.messages.forEach(m=>{if(m.by!==userId)m.read=true;});
}

export async function sendMessage(form) {
    if (form.dataset.sending) return;
    form.dataset.sending='true';
    const button=form.querySelector('button');button.disabled=true;
    const body=form.elements.text.value;
    // Keep the retry key until success, even when the connection drops.
    if (form.dataset.message!==body) { form.dataset.key=crypto.randomUUID();form.dataset.message=body; }
    try {
        await api(`/conversations/${form.dataset.thread}/messages`,{method:'POST',body:{body,client_key:form.dataset.key}});
        await loadConversations();render();
    } catch(error) { toast(error.message,true); }
    finally { delete form.dataset.sending;button.disabled=false; }
}

export async function featureAction(action,data) {
    if (!['wishlist','saved-remove','claim','voucher-pick','password-change','chat-start','chat-open','chat-older','inbox-filter','message-order','return','return-case','return-appeal','apply-seller'].includes(action)) return false;
    if (!getUser()) { view.afterLogin=route();go('login');return true; }
    switch(action) {
        case 'wishlist': {
            const product=getP(data.id),saved=S.wishlist.some(w=>w.productId===data.id);
            await api(`/saved-products/${product.backendId}`,{method:saved?'DELETE':'PUT'});await loadPrivate();render();toast(saved?'Removed from saved products':'Product saved');break;
        }
        case 'saved-remove': await api(`/saved-products/${data.id}`,{method:'DELETE'});await loadPrivate();render();break;
        case 'password-change':
            openModal('Change password',passwordField('current_password','Current password','current-password')+
                passwordField('new_password','New password','new-password','minlength="10" maxlength="72"')+
                passwordField('confirm_password','Confirm new password','new-password','minlength="10" maxlength="72"'),
                'Update password',async input=>{await api('/auth/password',{method:'POST',body:input});await loadPrivate();toast('Password updated. Other sessions have been signed out.');});break;
        case 'claim': await api(`/vouchers/${Number(data.id)}/claim`,{method:'POST'});await loadPrivate();render();toast('Voucher collected');break;
        case 'voucher-pick': {
            const scope=data.scope,store=data.store;
            const options=S.vouchers.filter(v=>v.scope===scope&&v.active&&(!store||v.storeId===store)&&S.claims.some(c=>c.voucherId===v.id&&!c.usedOrderId));
            const current=scope==='shop'?view.shopVoucher?.[store]:scope==='shipping'?view.shippingVoucher:view.checkoutVoucher;
            openModal('Choose voucher',select('voucher','Collected vouchers',[['','No voucher'],...options.map(v=>[v.id,`${v.name} · Min ${money(v.min)}`])],current||'')+
                '<p class="small muted">Collect offers in My Account → My vouchers. Eligibility and discounts are checked when you apply.</p>',
                'Apply',async input=>{
                    const previous={checkoutVoucher:view.checkoutVoucher,shippingVoucher:view.shippingVoucher,shopVoucher:{...view.shopVoucher}};
                    if(scope==='shop'){view.shopVoucher??={};view.shopVoucher[store]=input.voucher;}
                    else if(scope==='shipping')view.shippingVoucher=input.voucher;else view.checkoutVoucher=input.voucher;
                    try {view.quote=await quote();}catch(error){Object.assign(view,previous);throw error;}
                });break;
        }
        case 'chat-start': {
            const result=await api('/conversations',{method:'POST',body:{seller_id:getStore(data.store).backendId}});
            view.chat=String(result.thread_id);view.messageUnread=false;await loadConversations();await readChat(view.chat);go('messages');break;
        }
        case 'chat-open': view.chat=data.id;await readChat(data.id);render();break;
        case 'chat-older': {
            const chat=S.chats.find(c=>c.id===data.id),first=chat.messages[0];
            const result=await api(`/conversations/${data.id}`,{query:{before:first.id}});
            chat.messages.unshift(...result.messages.map(m=>({id:m.message_id,by:String(m.sender_id),text:m.body,time:m.created_at,read:Boolean(m.read_at)})));
            chat.hasOlder=result.has_older;render();break;
        }
        case 'inbox-filter': view.messageUnread=data.unread==='true';view.chat='';render();break;
        case 'message-order': viewInvoice(getO(data.id));break;
        case 'return': {
            const order=getO(data.id),key=crypto.randomUUID();
            openModal('Request return / refund',select('order_item_id','Purchased item',order.items.map(i=>[i.orderItemId,`${i.name} · ${i.variantName} (purchased ${i.qty})`]))+
                field('quantity','Quantity','number','1','required min="1" max="999"')+
                select('reason','Reason',['Damaged item','Wrong item','Missing parts','Not as described','Other'].map(x=>[x,x]))+
                select('solution','Requested resolution',[['return_refund','Return and refund'],['refund_only','Refund only']])+
                '<label class="field"><span>Explain the issue</span><textarea class="input" name="explanation" required minlength="10" maxlength="3000"></textarea></label>'+filesField('return')+
                `<p class="small muted">Requests are reviewed by the seller. Appeals go to management. Refund eligibility uses the amount paid after discounts. Request window: ${S.settings.returnDays} days after delivery/completion.</p>`,
                'Submit request',async input=>{const evidence_ids=await upload('return');await api('/returns',{method:'POST',body:{...input,order_item_id:Number(input.order_item_id),quantity:Number(input.quantity),evidence_ids,client_key:key}});await loadPrivate();toast('Return request submitted');},true);break;
        }
        case 'return-case': {
            const r=S.returns.find(r=>r.id===data.id);
            openModal(`Return case #${r.id}`,`<h3>${esc(r.product_name_snapshot)}</h3>${kv('Status',esc(r.status))}${kv('Reason',esc(r.reason))}${kv('Requested resolution',esc(r.solution.replaceAll('_',' ')))}${kv('Eligible merchandise amount',money(r.eligible_amount/100))}<p>${esc(r.notes)}</p>${r.seller_reply?`<div class="notice">Seller response: ${esc(r.seller_reply)}</div>`:''}<div class="evidence-gallery">${r.evidence.map(f=>`<a href="${esc(attachmentUrl(f.upload_id))}" target="_blank" rel="noopener"><img src="${esc(attachmentUrl(f.upload_id))}" alt="${esc(f.original_name)}"></a>`).join('')}</div><div class="timeline">${r.events.map(e=>`<div class="event"><b>${esc(e.status)}</b><p>${esc(e.message)} · ${dateTime(e.created_at)}</p></div>`).join('')}</div>`,'Close',()=>{},true);break;
        }
        case 'return-appeal':
            openModal('Appeal return decision','<label class="field"><span>Explain your appeal</span><textarea class="input" name="explanation" required minlength="10" maxlength="3000"></textarea></label>'+filesField('return'),
                'Submit appeal',async input=>{const evidence_ids=await upload('return');await api(`/returns/${Number(data.id)}/appeal`,{method:'POST',body:{explanation:input.explanation,evidence_ids}});await loadPrivate();toast('Appeal submitted for management review');},true);break;
        case 'apply-seller': {
            const last=S.applications?.[0],old=last?.status==='needs_information'?last:{};
            const fields=[['store_name','Store name',100],['business_name','Business / legal name',150],['business_type','Business type',80],['contact_name','Contact name',100],['contact_email','Contact email',255],['phone','Mobile number',11],['address_text','Business address',500],['description','Describe your business',2000]];
            openModal('Apply to sell',fields.map(([key,label,max])=>field(key,label,key==='contact_email'?'email':key==='phone'?'tel':'text',old[key]|| (key==='contact_name'?getUser().name:key==='contact_email'?getUser().email:key==='phone'?getUser().phone:''),`required maxlength="${max}" ${key==='phone'?'minlength="11" pattern="[0-9]{11}" inputmode="numeric"':''}`)).join('')+filesField('application'),
                'Submit for review',async input=>{const document_ids=await upload('application');await api('/seller-applications',{method:'POST',body:{...input,document_ids}});await loadPrivate();toast('Application submitted for management review');},true);break;
        }
    }
    return true;
}
