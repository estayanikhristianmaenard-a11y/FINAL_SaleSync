<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class Shipment extends BaseModel { public function all(): array{return $this->rows('SELECT * FROM shipments WHERE store_id=? ORDER BY id DESC',[$this->storeId()]);} }
