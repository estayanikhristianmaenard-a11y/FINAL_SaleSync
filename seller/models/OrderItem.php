<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class OrderItem extends BaseModel {
    public function forOrder(int $orderId): array { return $this->rows('SELECT oi.*,p.name,p.sku FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? AND oi.store_id=? ORDER BY oi.id',[$orderId,$this->storeId()]); }
}
