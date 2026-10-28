<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class AnalyticsService {
    public function __construct(private PDO $db) {}
    public function compareInterest(int $storeId): array {
        $q=$this->db->prepare('SELECT p.id AS product_id,p.name AS product_name,COUNT(pc.id) AS compare_count FROM products p LEFT JOIN product_comparisons pc ON pc.product_id=p.id AND pc.store_id=p.store_id WHERE p.store_id=? AND p.archived=0 GROUP BY p.id,p.name ORDER BY compare_count DESC,p.name COLLATE NOCASE');
        $q->execute([$storeId]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function productPerformance(int $storeId): array {
        $q=$this->db->prepare("SELECT p.id AS product_id,p.name AS product_name,COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND oi.store_id=p.store_id AND o.paid=1 AND o.status='Completed'),0) AS units_sold,COALESCE((SELECT SUM(oi.quantity*oi.unit_price) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND oi.store_id=p.store_id AND o.paid=1 AND o.status='Completed'),0) AS revenue_generated,COALESCE((SELECT COUNT(*) FROM product_comparisons pc WHERE pc.product_id=p.id AND pc.store_id=p.store_id),0) AS compare_count,p.published AS published_status FROM products p WHERE p.store_id=? AND p.archived=0 ORDER BY units_sold DESC,p.name COLLATE NOCASE");
        $q->execute([$storeId]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function storeRating(int $storeId): float {
        // Prefer the approved review rows when a reviews table is installed; stores.rating
        // remains the canonical aggregate for deployments using the base seller schema.
        $table=$this->db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='reviews'")->fetchColumn();
        if ($table!==false) {
            $columns=array_column($this->db->query('PRAGMA table_info(reviews)')->fetchAll(PDO::FETCH_ASSOC),'name');
            if (in_array('rating',$columns,true) && in_array('product_id',$columns,true) && in_array('status',$columns,true)) {
                $q=$this->db->prepare("SELECT AVG(r.rating) FROM reviews r JOIN products p ON p.id=r.product_id WHERE p.store_id=? AND p.archived=0 AND r.status IN ('Approved','Published')");
                $q->execute([$storeId]); $average=$q->fetchColumn();
                if ($average!==false && $average!==null) return (float)$average;
            }
        }
        $q=$this->db->prepare('SELECT rating FROM stores WHERE id=?'); $q->execute([$storeId]);
        $value=$q->fetchColumn(); if ($value===false) throw new \RuntimeException('Store not found',404);
        return (float)$value;
    }
}
