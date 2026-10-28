<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class Payout extends BaseModel {
 public function all(): array{return $this->rows('SELECT * FROM payouts WHERE store_id=? ORDER BY id DESC',[$this->storeId()]);}
 public function find(int $id): array{return $this->owned('payouts',$id);}
 public function history(int $id): array{$this->find($id);return $this->rows('SELECT h.* FROM payout_history h JOIN payouts p ON p.id=h.payout_id WHERE h.payout_id=? AND p.store_id=? ORDER BY h.id',[$id,$this->storeId()]);}
}
