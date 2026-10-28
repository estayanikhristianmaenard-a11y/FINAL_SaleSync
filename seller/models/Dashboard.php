<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Dashboard extends BaseModel {
    public function recentActivity(int $limit=20): array {
        return $this->rows('SELECT id,type,title,body,is_read,created_at FROM notifications WHERE store_id=? ORDER BY created_at DESC,id DESC LIMIT ?',[$this->storeId(),$limit]);
    }
    public function lowStockProducts(int $limit=20): array {
        return $this->rows('SELECT id AS product_id,name,sku,stock,reserved,(stock-reserved) AS available_stock,published FROM products WHERE store_id=? AND archived=0 AND (stock-reserved)<6 ORDER BY (stock-reserved) ASC,name COLLATE NOCASE LIMIT ?',[$this->storeId(),$limit]);
    }
}
