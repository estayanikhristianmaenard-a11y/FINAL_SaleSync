<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ProductStatistic extends BaseModel {
    public function compareInterest(int $storeId): array {
        if ($storeId!==$this->storeId()) throw new \RuntimeException('Store not found',404);
        return (new AnalyticsService($this->db))->compareInterest($storeId);
    }
    public function performance(int $storeId): array {
        if ($storeId!==$this->storeId()) throw new \RuntimeException('Store not found',404);
        return (new AnalyticsService($this->db))->productPerformance($storeId);
    }
    public function storeRating(int $storeId): float {
        if ($storeId!==$this->storeId()) throw new \RuntimeException('Store not found',404);
        return (new AnalyticsService($this->db))->storeRating($storeId);
    }
}
