<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class InsightsController extends Controller {
    private function insights(): Insight { return new Insight($this->db,$this->sellerId); }
    private function statistics(): ProductStatistic { return new ProductStatistic($this->db,$this->sellerId); }
    public function getOverview($id=null,array $data=[]): array { return $this->insights()->overview(); }
    public function getPaidOrders($id=null,array $data=[]): array { return ['paid_orders'=>$this->insights()->paidOrders()]; }
    public function getProductListings($id=null,array $data=[]): array { return ['product_listings'=>$this->insights()->productListings()]; }
    public function getSoldUnits($id=null,array $data=[]): array { return ['total_sold_units'=>$this->insights()->soldUnits()]; }
    public function getStoreRating($id=null,array $data=[]): array { return ['store_rating'=>$this->statistics()->storeRating($this->storeId())]; }
    public function getCompareInterest($id=null,array $data=[]): array { return ['products'=>$this->statistics()->compareInterest($this->storeId())]; }
    public function getProductPerformance($id=null,array $data=[]): array { return ['products'=>$this->statistics()->performance($this->storeId())]; }
    public function getSalesTrend($id=null,array $data=[]): array {
        $days=InsightValidator::period($_GET['days']??$data['days']??12);
        return ['period_days'=>$days,'sales_trend'=>$this->insights()->salesTrend($days)];
    }
    public function getStoreSummary($id=null,array $data=[]): array { return $this->insights()->storeSummary(); }
}
