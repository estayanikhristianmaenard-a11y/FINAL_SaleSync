<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class Notification extends BaseModel { public function all(): array{return $this->rows('SELECT * FROM notifications WHERE store_id=? ORDER BY id DESC',[$this->storeId()]);} }
