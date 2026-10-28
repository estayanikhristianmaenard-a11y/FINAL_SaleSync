<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class RefundService {
    public function __construct(private PDO $db) {}
    public function createForOrder(int $orderId,int $storeId,string $reason=''): array {
        $q=$this->db->prepare('SELECT o.paid,COALESCE(SUM(oi.quantity*oi.unit_price),0) amount,COUNT(oi.id) item_count FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.id=? AND oi.store_id=? GROUP BY o.id');
        $q->execute([$orderId,$storeId]);$data=$q->fetch(PDO::FETCH_ASSOC);
        if(!$data)throw new \RuntimeException('No order items for this store',404);
        if(!(int)$data['paid'])throw new \RuntimeException('This order is not eligible for a refund',409);
        $check=$this->db->prepare("SELECT id FROM refunds WHERE order_id=? AND store_id=? AND status IN ('Pending','Approved') LIMIT 1");$check->execute([$orderId,$storeId]);
        if($check->fetchColumn())throw new \RuntimeException('A refund already exists for this order',409);
        $q=$this->db->prepare('INSERT INTO refunds(order_id,store_id,amount,reason) VALUES(?,?,?,?)');$q->execute([$orderId,$storeId,(float)$data['amount'],trim($reason)?:null]);$id=(int)$this->db->lastInsertId();
        (new NotificationService($this->db))->create($storeId,'refund_requested','Refund requested','Refund request for order #'.$orderId.' is pending review.');
        return $this->get($id,$storeId);
    }
    public function get(int $id,int $storeId): array { $q=$this->db->prepare('SELECT * FROM refunds WHERE id=? AND store_id=?');$q->execute([$id,$storeId]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new \RuntimeException('Refund not found',404);return $r; }
    public function decide(int $id,int $storeId,bool $approve): array {
        $r=$this->get($id,$storeId);if($r['status']!=='Pending')throw new \RuntimeException('Only pending refunds can be reviewed',409);
        $status=$approve?'Approved':'Rejected';$q=$this->db->prepare('UPDATE refunds SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND store_id=? AND status=\'Pending\'');$q->execute([$status,$id,$storeId]);
        if($q->rowCount()!==1)throw new \RuntimeException('Refund status changed; reload and retry',409);
        if($approve)(new NotificationService($this->db))->create($storeId,'refund_approved','Refund approved','Refund for order #'.$r['order_id'].' was approved.');
        return $this->get($id,$storeId);
    }
}
