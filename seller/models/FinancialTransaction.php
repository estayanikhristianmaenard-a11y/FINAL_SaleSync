<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class FinancialTransaction extends BaseModel {
    public function forPayout(int $payoutId): array {
        $this->owned('payouts',$payoutId);
        return $this->rows('SELECT id,payout_id,order_id,type,amount,description,created_at FROM financial_transactions WHERE payout_id=? AND store_id=? ORDER BY id',[$payoutId,$this->storeId()]);
    }
}
