<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Stock extends BaseModel {
    public function all(): array { return $this->rows('SELECT id product_id,name,sku,stock total_stock,reserved reserved_stock,stock-reserved available_stock,pos_ref pos_reference FROM products WHERE store_id=? AND archived=0 ORDER BY name',[$this->storeId()]); }
    public function forProduct(int $id): array { return $this->owned('products',$id); }
}
