/* Owner: Core team | Purpose: Cross-module notification/event effects | Reads: SaleSync core state | Writes: via shared core actions | Emits: core events | Listens: shared store | Test: open its portal and exercise the relevant screens. */
BUS.on('seller.application',x=>{notifyRole('Management Administrator','New seller application: '+x.name,'Seller application');job(x.ownerUserId,'Seller application received')});
BUS.on('seller.approved',x=>{note(x.ownerUserId,'Your seller application was '+x.status.toLowerCase(),'Seller application');job(x.ownerUserId,'Store application decision')});
BUS.on('product.requested',x=>notifyRole('Management Administrator','Product request: '+x.name,'Products'));
BUS.on('product.assigned',x=>note(ownerOfStore(x.storeId),'Product assigned: '+x.name+' (Draft)','Products'));
BUS.on('payment.paid',x=>{const o=getO(x.orderId);note(o.customerId,'Payment confirmed for '+o.id,'Order');job(o.customerId,'Payment receipt');o.shipments.forEach(s=>{note(ownerOfStore(s.storeId),'New paid order: '+o.id,'Orders');job(ownerOfStore(s.storeId),'New order notification')})});
BUS.on('payment.failed',x=>{const o=getO(x.orderId);note(o.customerId,'Payment '+x.status.toLowerCase()+' for '+o.id,'Order')});
BUS.on('shipment.updated',x=>{const o=orderOfShipment(x.shipmentId);if(o)note(o.customerId,'Shipment update: '+x.status+' for '+o.id,'Order')});
BUS.on('return.requested',r=>{note(ownerOfStore(getP(r.itemId)?.storeId),'Return request on '+r.orderId,'Returns');notifyRole('Management Administrator','New return request '+r.id,'Returns')});
BUS.on('return.updated',r=>{const o=getO(r.orderId);note(o?.customerId,'Return '+r.id+': '+r.status,'Returns');if(r.status==='Escalated')notifyRole('Management Administrator','Return escalation '+r.id,'Returns')});
BUS.on('review.created',r=>note(ownerOfStore(getP(r.productId)?.storeId),'New product review on '+r.productId,'Reviews'));
BUS.on('chat.sent',x=>note(x.to,'New message in '+x.storeName,'Messages'));
