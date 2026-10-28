<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class DashboardService {
    public function __construct(private PDO $db,private int $sellerId) {}
    private function metrics(): array { return (new AnalyticsAggregatorService($this->db,$this->sellerId))->metrics(); }
    private function storeId(): int {
        $q=$this->db->prepare('SELECT id FROM stores WHERE seller_id=?');$q->execute([$this->sellerId]);$id=$q->fetchColumn();
        if($id===false) throw new \RuntimeException('Seller store was not found',404);
        return (int)$id;
    }
    private function finance(): array { return (new FinanceService($this->db))->summary($this->storeId()); }
    public function dashboard(int $trendDays=12): array {
        $metrics=$this->metrics();$finance=$this->finance();
        return [
            'to_process'=>$metrics['to_process']??0,
            'ready_for_handover'=>$metrics['ready_for_handover']??0,
            'return_cases'=>$metrics['return_cases']??0,
            'low_stock'=>$metrics['low_stock']??0,
            'unread_messages'=>$metrics['unread_messages']??0,
            'pending_requests'=>$metrics['pending_requests']??0,
            'pending_returns'=>$metrics['pending_returns']??0,
            'paid_sales'=>(float)$finance['gross_sales'],
            'net_income'=>(float)$finance['net'],
            'published_products'=>$metrics['published_products']??0,
            'sales_trend'=>(new SalesTrendService($this->db))->forStore($this->storeId(),$trendDays),
        ];
    }
    public function statistics(): array {
        $metrics=$this->metrics();
        return array_intersect_key($metrics,array_flip(['to_process','ready_for_handover','return_cases','low_stock']));
    }
    public function todoChecklist(): array {
        $metrics=$this->metrics();
        return ['paid_orders_to_process'=>$metrics['to_process']??0,'unread_messages'=>$metrics['unread_messages']??0,'pending_requests'=>$metrics['pending_requests']??0,'pending_returns'=>$metrics['pending_returns']??0];
    }
    public function shopInsights(int $trendDays=12): array {
        $metrics=$this->metrics();$finance=$this->finance();
        return ['paid_sales'=>(float)$finance['gross_sales'],'net_income'=>(float)$finance['net'],'published_products'=>$metrics['published_products']??0,'sales_trend'=>(new SalesTrendService($this->db))->forStore($this->storeId(),$trendDays)];
    }
    public function recentActivity(int $limit=20): array { return (new Dashboard($this->db,$this->sellerId))->recentActivity($limit); }
    public function lowStockProducts(int $limit=20): array { return (new Dashboard($this->db,$this->sellerId))->lowStockProducts($limit); }
}
