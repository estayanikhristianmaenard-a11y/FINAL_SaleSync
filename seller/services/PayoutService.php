<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class PayoutService {
    public function __construct(private PDO $db) {}
    public function all(int $storeId): array { $q=$this->db->prepare('SELECT p.*,s.seller_id FROM payouts p JOIN stores s ON s.id=p.store_id WHERE p.store_id=? ORDER BY p.id DESC');$q->execute([$storeId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row)$row=$this->present($row);unset($row);return $rows; }
    public function get(int $id,int $storeId): array { $q=$this->db->prepare('SELECT p.*,s.seller_id FROM payouts p JOIN stores s ON s.id=p.store_id WHERE p.id=? AND p.store_id=?');$q->execute([$id,$storeId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new \RuntimeException('Payout not found',404);$row=$this->present($row);$row['transactions']=$this->transactions($id,$storeId);return $row; }
    public function history(int $id,int $storeId): array { $this->get($id,$storeId);$q=$this->db->prepare('SELECT h.id,h.payout_id,h.status,h.note,h.changed_at FROM payout_history h JOIN payouts p ON p.id=h.payout_id WHERE h.payout_id=? AND p.store_id=? ORDER BY h.id');$q->execute([$id,$storeId]);return $q->fetchAll(PDO::FETCH_ASSOC); }
    public function transactions(int $id,int $storeId): array { $this->getOwned($id,$storeId);$q=$this->db->prepare('SELECT t.id,t.payout_id,t.order_id,t.type,t.amount,t.description,t.created_at FROM financial_transactions t JOIN payouts p ON p.id=t.payout_id AND p.store_id=t.store_id WHERE t.payout_id=? AND t.store_id=? ORDER BY t.id');$q->execute([$id,$storeId]);return $q->fetchAll(PDO::FETCH_ASSOC); }
    public function released(int $storeId): array { return $this->filterStatuses($storeId,['Released','Paid']); }
    public function pending(int $storeId): array { return $this->filterStatuses($storeId,['Pending','Accrued']); }
    private function filterStatuses(int $storeId,array $statuses): array { $marks=implode(',',array_fill(0,count($statuses),'?'));$q=$this->db->prepare("SELECT p.*,s.seller_id FROM payouts p JOIN stores s ON s.id=p.store_id WHERE p.store_id=? AND p.status IN ($marks) ORDER BY p.id DESC");$q->execute([$storeId,...$statuses]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row)$row=$this->present($row);unset($row);return $rows; }
    private function getOwned(int $id,int $storeId): void { $q=$this->db->prepare('SELECT 1 FROM payouts WHERE id=? AND store_id=?');$q->execute([$id,$storeId]);if(!$q->fetchColumn())throw new \RuntimeException('Payout not found',404); }
    private function present(array $row): array {
        $raw=(string)$row['status'];$row['original_status']=$raw;
        $row['status']=match($raw){'Paid','Released'=>'Released','Accrued','Pending'=>'Pending','Eligible','Processing'=>'Processing','Failed'=>'Failed','Cancelled'=>'Cancelled',default=>$raw};
        $row['seller_id']=(int)$row['seller_id'];$row['gross_sales']=(float)$row['gross'];$row['seller_discount']=(float)($row['seller_discount']??0);$row['refund_adjustment']=abs((float)$row['refund_adjust']);$row['refundAdjust']=$row['refund_adjustment'];$row['net_payout']=(float)$row['amount'];$row['orderId']=$row['order_id'];$row['created']=$row['created_at'];$row['releasedAt']=$row['released_at'];return $row;
    }
}
