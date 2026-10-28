<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class DashboardController extends Controller {
    private function dashboardService(): DashboardService { return new DashboardService($this->db,$this->sellerId); }
    public function getDashboard($id=null,array $data=[]): array {
        $days=DashboardValidator::days($_GET['days']??$data['days']??12);
        return $this->dashboardService()->dashboard($days);
    }
    public function getStatistics($id=null,array $data=[]): array { return $this->dashboardService()->statistics(); }
    public function getTodoChecklist($id=null,array $data=[]): array { return $this->dashboardService()->todoChecklist(); }
    public function getShopInsights($id=null,array $data=[]): array {
        $days=DashboardValidator::days($_GET['days']??$data['days']??12);
        return $this->dashboardService()->shopInsights($days);
    }
    public function getRecentActivity($id=null,array $data=[]): array {
        $limit=DashboardValidator::limit($_GET['limit']??$data['limit']??20);
        return ['activities'=>$this->dashboardService()->recentActivity($limit)];
    }
    public function getLowStockProducts($id=null,array $data=[]): array {
        $limit=DashboardValidator::limit($_GET['limit']??$data['limit']??20);
        return ['products'=>$this->dashboardService()->lowStockProducts($limit)];
    }

    // Keep the previous dashboard API actions available for existing callers.
    public function overview($id=null,array $data=[]): array {
        $storeId=$this->storeId();$sales=(new SalesService($this->db))->summary($storeId);
        $products=(int)($this->one('SELECT COUNT(*) AS n FROM products WHERE store_id=? AND archived=0',[$storeId])['n']??0);
        $low=(int)($this->one('SELECT COUNT(*) AS n FROM products WHERE store_id=? AND archived=0 AND stock-reserved<6',[$storeId])['n']??0);
        $shipments=$this->rows('SELECT status,COUNT(*) AS count FROM shipments WHERE store_id=? GROUP BY status',[$storeId]);
        return ['orders'=>(int)$sales['orders'],'products'=>$products,'units_sold'=>(int)$sales['units'],'gross_sales'=>(float)$sales['gross'],'low_stock'=>$low,'shipments'=>$shipments,'rating'=>(float)($this->one('SELECT rating FROM stores WHERE id=?',[$storeId])['rating']??0)];
    }
    public function salesStatistics($id=null,array $data=[]): array { return (new SalesService($this->db))->summary($this->storeId()); }
    public function productPerformance($id=null,array $data=[]): array {
        return $this->rows('SELECT p.id,p.name,p.sku,COALESCE(SUM(CASE WHEN o.paid=1 THEN oi.quantity ELSE 0 END),0) AS units_sold,COALESCE(SUM(CASE WHEN o.paid=1 THEN oi.quantity*oi.unit_price ELSE 0 END),0) AS gross_sales FROM products p LEFT JOIN order_items oi ON oi.product_id=p.id AND oi.store_id=p.store_id LEFT JOIN orders o ON o.id=oi.order_id WHERE p.store_id=? AND p.archived=0 GROUP BY p.id ORDER BY units_sold DESC,p.id',[$this->storeId()]);
    }
}
