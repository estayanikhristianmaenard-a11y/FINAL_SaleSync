<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class SalesTrend extends BaseModel {
    public function forStore(int $storeId,int $days=12): array {
        if ($storeId!==$this->storeId()) throw new \RuntimeException('Store not found',404);
        return (new SalesTrendService($this->db))->forStore($storeId,$days);
    }
}
