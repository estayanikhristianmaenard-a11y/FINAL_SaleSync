<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Insight extends BaseModel {
    public function overview(): array {
        $store=$this->storeId();
        $products=$this->one('SELECT COUNT(*) AS product_listings FROM products WHERE store_id=? AND archived=0',[$store]);
        $statistics=new ProductStatistic($this->db,$this->sellerId);
        $rating=$statistics->storeRating($store);
        $compareInterest=$statistics->compareInterest($store);
        return [
            'paid_orders'=>$this->paidOrders(),
            'product_listings'=>(int)($products['product_listings']??0),
            'total_sold_units'=>$this->soldUnits(),
            'store_rating'=>$rating,
            'times_compared'=>array_sum(array_map(static fn(array $item): int => (int)$item['compare_count'],$compareInterest)),
            'compare_interest'=>$compareInterest,
        ];
    }
    public function paidOrders(): int {
        $row=$this->one("SELECT COUNT(DISTINCT o.id) AS total FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.store_id=? AND o.paid=1 AND o.status='Completed'",[$this->storeId()]);
        return (int)($row['total']??0);
    }
    public function productListings(): int {
        $row=$this->one('SELECT COUNT(*) AS total FROM products WHERE store_id=? AND archived=0',[$this->storeId()]);
        return (int)($row['total']??0);
    }
    public function soldUnits(): int {
        $row=$this->one("SELECT COALESCE(SUM(oi.quantity),0) AS total FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.store_id=? AND o.paid=1 AND o.status='Completed'",[$this->storeId()]);
        return (int)($row['total']??0);
    }
    public function salesTrend(int $days=12): array { return (new SalesTrendService($this->db))->forStore($this->storeId(),$days); }
    public function storeSummary(): array { return $this->overview(); }
}
