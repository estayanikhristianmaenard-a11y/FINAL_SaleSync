<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class PayoutController extends Controller {
    private function service(): PayoutService { return new PayoutService($this->db); }
    public function getPayouts($id=null,array $d=[]): array { return $this->service()->all($this->storeId()); }
    public function getPayout($id=null,array $d=[]): array { return $this->service()->get(PayoutValidator::id($id??$d['payout_id']??null),$this->storeId()); }
    public function getPayoutHistory($id=null,array $d=[]): array { return $this->service()->history(PayoutValidator::id($id??$d['payout_id']??null),$this->storeId()); }
    public function getTransactionBreakdown($id=null,array $d=[]): array { return $this->service()->transactions(PayoutValidator::id($id??$d['payout_id']??null),$this->storeId()); }
    public function getReleasedPayouts($id=null,array $d=[]): array { return $this->service()->released($this->storeId()); }
    public function getPendingPayouts($id=null,array $d=[]): array { return $this->service()->pending($this->storeId()); }
}
