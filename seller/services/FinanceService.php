<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class FinanceService {
    public function __construct(private PDO $db) {}
    public function summary(int $storeId): array {
        $gross=$this->grossSales($storeId);$discounts=$this->discounts($storeId);$refunds=$this->refundAdjustments($storeId);$commission=$this->commission($storeId);
        return ['gross'=>$gross,'gross_sales'=>$gross,'sellerDiscount'=>$discounts,'seller_discounts'=>$discounts,'refunds'=>$refunds,'refund_adjustments'=>$refunds,'commission'=>$commission,'net'=>$gross-$discounts-$refunds-$commission,'orders'=>$this->orderCount($storeId)];
    }
    public function grossSales(int $storeId): float {
        $q=$this->db->prepare('SELECT COALESCE(SUM(oi.quantity*oi.unit_price),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.store_id=? AND o.paid=1');$q->execute([$storeId]);return (float)$q->fetchColumn();
    }
    public function discounts(int $storeId): float { $q=$this->db->prepare('SELECT COALESCE(SUM(seller_discount),0) FROM payouts WHERE store_id=?');$q->execute([$storeId]);return (float)$q->fetchColumn(); }
    public function refundAdjustments(int $storeId): float {
        $p=$this->db->prepare('SELECT COALESCE(SUM(ABS(refund_adjust)),0) FROM payouts WHERE store_id=?');$p->execute([$storeId]);$payoutAdjustments=(float)$p->fetchColumn();
        $r=$this->db->prepare("SELECT COALESCE(SUM(r.amount),0) FROM refunds r WHERE r.store_id=? AND r.status='Approved' AND NOT EXISTS(SELECT 1 FROM payouts p WHERE p.store_id=r.store_id AND p.order_id=r.order_id AND ABS(p.refund_adjust)>0)");$r->execute([$storeId]);return $payoutAdjustments+(float)$r->fetchColumn();
    }
    public function commission(int $storeId): float { $q=$this->db->prepare('SELECT COALESCE(SUM(commission),0) FROM payouts WHERE store_id=?');$q->execute([$storeId]);return (float)$q->fetchColumn(); }
    public function netIncome(int $storeId): float { $s=$this->summary($storeId);return (float)$s['net']; }
    public function orderCount(int $storeId): int { $q=$this->db->prepare('SELECT COUNT(DISTINCT o.id) FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.store_id=? AND o.paid=1');$q->execute([$storeId]);return (int)$q->fetchColumn(); }
    public function orderIncome(int $orderId,int $storeId): array {
        $q=$this->db->prepare('SELECT o.id order_id,o.customer_id,o.status order_status,o.total,o.paid,o.created_at FROM orders o WHERE o.id=? AND EXISTS(SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.store_id=?)');$q->execute([$orderId,$storeId]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!$order)throw new \RuntimeException('Order not found for this store',404);
        $items=$this->db->prepare('SELECT oi.product_id,p.name product_name,p.sku,oi.quantity,oi.unit_price,oi.quantity*oi.unit_price gross FROM order_items oi JOIN products p ON p.id=oi.product_id AND p.store_id=oi.store_id WHERE oi.order_id=? AND oi.store_id=? ORDER BY oi.id');$items->execute([$orderId,$storeId]);$order['items']=$items->fetchAll(PDO::FETCH_ASSOC);
        $payouts=$this->db->prepare('SELECT * FROM payouts WHERE order_id=? AND store_id=? ORDER BY id');$payouts->execute([$orderId,$storeId]);$order['payouts']=$payouts->fetchAll(PDO::FETCH_ASSOC);
        $order['gross_sales']=array_sum(array_map(fn($item)=>(float)$item['gross'],$order['items']));$order['commission']=array_sum(array_map(fn($p)=>(float)$p['commission'],$order['payouts']));$order['seller_discount']=array_sum(array_map(fn($p)=>(float)$p['seller_discount'],$order['payouts']));$order['refund_adjustment']=array_sum(array_map(fn($p)=>abs((float)$p['refund_adjust']),$order['payouts']));$order['net_payout_basis']=$order['gross_sales']-$order['commission']-$order['seller_discount']-$order['refund_adjustment'];return $order;
    }
}
