<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class Order extends BaseModel { public function all(): array{return $this->rows('SELECT o.*,sh.id shipment_id,sh.status shipment_status,sh.tracking FROM orders o JOIN shipments sh ON sh.order_id=o.id WHERE sh.store_id=? ORDER BY o.id DESC',[$this->storeId()]);} }
