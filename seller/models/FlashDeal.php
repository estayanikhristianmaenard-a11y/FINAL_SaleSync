<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class FlashDeal extends BaseModel {
 public function all(): array{return $this->rows('SELECT d.*,p.name product_name FROM flash_deals d JOIN products p ON p.id=d.product_id AND p.store_id=d.store_id WHERE d.store_id=? ORDER BY d.id DESC',[$this->storeId()]);}
 public function find(int $id): array{return $this->owned('flash_deals',$id);}
}
