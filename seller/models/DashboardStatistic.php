<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class DashboardStatistic extends BaseModel {
    public function aggregate(): array {
        $storeId=$this->storeId();
        $row=$this->one("SELECT
            (SELECT COUNT(*) FROM shipments sh JOIN orders o ON o.id=sh.order_id WHERE sh.store_id=? AND sh.status='To Process' AND o.paid=1) AS to_process,
            (SELECT COUNT(*) FROM shipments sh JOIN orders o ON o.id=sh.order_id WHERE sh.store_id=? AND sh.status='Ready for Shipment' AND o.paid=1) AS ready_for_handover,
            (SELECT COUNT(*) FROM returns r WHERE r.store_id=? AND r.status NOT IN ('Refunded','Refund Denied')) AS return_cases,
            (SELECT COUNT(*) FROM products p WHERE p.store_id=? AND p.archived=0 AND (p.stock-p.reserved)<6) AS low_stock,
            (SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.store_id=? AND m.sender_type<>'seller' AND m.is_read=0) AS unread_messages,
            (SELECT COUNT(*) FROM product_requests pr WHERE pr.store_id=? AND pr.status='Pending') AS pending_requests,
            (SELECT COUNT(*) FROM returns r WHERE r.store_id=? AND r.status='Requested') AS pending_returns,
            (SELECT COUNT(*) FROM products p WHERE p.store_id=? AND p.archived=0 AND p.published=1) AS published_products",[$storeId,$storeId,$storeId,$storeId,$storeId,$storeId,$storeId,$storeId]);
        return array_map('intval',$row??[]);
    }
}
