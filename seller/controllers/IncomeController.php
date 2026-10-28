<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class IncomeController extends Controller {
    private function finance(): FinanceService { return new FinanceService($this->db); }
    public function getSummary($id=null,array $d=[]): array { return $this->finance()->summary($this->storeId()); }
    public function summary($id=null,array $d=[]): array { return $this->getSummary($id,$d); }
    public function getGrossSales($id=null,array $d=[]): array { return ['gross_sales'=>$this->finance()->grossSales($this->storeId())]; }
    public function getDiscounts($id=null,array $d=[]): array { return ['seller_discounts'=>$this->finance()->discounts($this->storeId())]; }
    public function getRefundAdjustments($id=null,array $d=[]): array { return ['refund_adjustments'=>$this->finance()->refundAdjustments($this->storeId())]; }
    public function getNetIncome($id=null,array $d=[]): array { return ['net_payout_basis'=>$this->finance()->netIncome($this->storeId())]; }
    public function getOrderIncome($id=null,array $d=[]): array { return $this->finance()->orderIncome(FinanceValidator::id($id??$d['order_id']??null,'order'),$this->storeId()); }
    public function earnings($id=null,array $d=[]): array { return $this->getSummary($id,$d); }
    public function payouts($id=null,array $d=[]): array { return (new PayoutService($this->db))->all($this->storeId()); }
}
