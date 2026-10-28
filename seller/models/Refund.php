<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Refund extends BaseModel {
    public function all(): array { return $this->rows('SELECT * FROM refunds WHERE store_id=? ORDER BY id DESC',[$this->storeId()]); }
    public function find(int $id): array { return $this->owned('refunds',$id); }
}
