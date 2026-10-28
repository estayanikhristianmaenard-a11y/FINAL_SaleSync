<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class SalesTrendService {
    public function __construct(private PDO $db) {}
    /** Return the latest twelve days including zero-sale days, oldest first. */
    public function forStore(int $storeId,int $days=12): array {
        if ($days<1 || $days>366) throw new \InvalidArgumentException('Period must be between 1 and 366 days');
        $q=$this->db->prepare("SELECT date(o.created_at) AS day, COALESCE(SUM(oi.quantity*oi.unit_price),0) AS gross_sales FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.store_id=? AND o.paid=1 AND date(o.created_at)>=date('now',?) GROUP BY date(o.created_at) ORDER BY day");
        $q->execute([$storeId,'-'.$days.' days']);
        $totals=[]; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $totals[$row['day']]=(float)$row['gross_sales'];
        $refund=$this->db->prepare("SELECT date(created_at) AS day, COALESCE(SUM(amount),0) AS total FROM refunds WHERE store_id=? AND status='Approved' AND date(created_at)>=date('now',?) GROUP BY date(created_at)");
        $refund->execute([$storeId,'-'.$days.' days']);
        $refunds=[]; foreach ($refund->fetchAll(PDO::FETCH_ASSOC) as $row) $refunds[$row['day']]=(float)$row['total'];
        $result=[];
        for ($offset=$days-1;$offset>=0;$offset--) {
            $day=(new \DateTimeImmutable('today'))->modify('-'.$offset.' days')->format('Y-m-d');
            $gross=$totals[$day]??0.0; $adjustment=$refunds[$day]??0.0;
            $result[]=['date'=>$day,'gross_sales'=>round($gross,2),'refund_adjustment'=>round($adjustment,2),'net_sales'=>round($gross-$adjustment,2)];
        }
        return $result;
    }
}
