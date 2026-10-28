<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class ReturnService {
    public function __construct(private PDO $db) {}
    public function all(int $storeId): array {
        $returns=$this->rows('SELECT r.*,p.name product_name,o.customer_id,s.seller_id,i.received_at,i.inspected_at,i.outcome inspection_outcome,i.notes inspection_notes FROM returns r JOIN order_items oi ON oi.order_id=r.order_id AND oi.product_id=r.product_id AND oi.store_id=r.store_id JOIN products p ON p.id=r.product_id AND p.store_id=r.store_id JOIN orders o ON o.id=r.order_id JOIN stores s ON s.id=r.store_id LEFT JOIN return_inspections i ON i.return_id=r.id WHERE r.store_id=? ORDER BY r.id DESC',[$storeId]);
        foreach($returns as &$return)$return['evidence']=$this->evidence((int)$return['id'],$storeId);unset($return);return $returns;
    }
    public function get(int $id,int $storeId): array {
        $row=$this->one('SELECT r.*,p.name product_name,p.sku product_sku,o.customer_id,o.recipient_name,o.recipient_phone,o.address order_address,s.seller_id,i.received_at,i.inspected_at,i.outcome inspection_outcome,i.notes inspection_notes FROM returns r JOIN order_items oi ON oi.order_id=r.order_id AND oi.product_id=r.product_id AND oi.store_id=r.store_id JOIN products p ON p.id=r.product_id AND p.store_id=r.store_id JOIN orders o ON o.id=r.order_id JOIN stores s ON s.id=r.store_id LEFT JOIN return_inspections i ON i.return_id=r.id WHERE r.id=? AND r.store_id=?',[$id,$storeId]);
        if(!$row)throw new \RuntimeException('Return not found',404);$row['evidence']=$this->evidence($id,$storeId);$row['sellerReply']=$row['seller_notes'];return $row;
    }
    public function history(int $id,int $storeId): array { $r=$this->get($id,$storeId);$history=$this->rows('SELECT h.id,h.status,h.notes,h.created_at,h.actor_seller_id FROM return_history h JOIN returns r ON r.id=h.return_id WHERE h.return_id=? AND r.store_id=? ORDER BY h.id',[$id,$storeId]);array_unshift($history,['id'=>null,'status'=>'Requested','notes'=>$r['reason'],'created_at'=>$r['created_at'],'actor_seller_id'=>null]);return $history; }
    public function appendHistory(int $returnId,string $status,string $notes,int $sellerId): void { $q=$this->db->prepare('INSERT INTO return_history(return_id,status,notes,actor_seller_id) VALUES(?,?,?,?)');$q->execute([$returnId,$status,$notes===''?null:$notes,$sellerId]); }
    public function evidence(int $returnId,int $storeId): array { $items=$this->rows('SELECT e.id,e.return_id,e.path,e.mime_type,e.uploaded_at,e.seller_id FROM return_evidence e JOIN returns r ON r.id=e.return_id AND r.store_id=e.store_id WHERE e.return_id=? AND e.store_id=? ORDER BY e.id',[$returnId,$storeId]);foreach($items as &$item){$item['src']='/seller/'.$item['path'];$item['name']=basename($item['path']);}unset($item);return $items; }
    private function rows(string $sql,array $args): array { $q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql,array $args): ?array { $q=$this->db->prepare($sql);$q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null; }
}
