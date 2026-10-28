<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class FlashDealService {
    public function __construct(private PDO $db) {}
    public function all(int $storeId): array { $q=$this->db->prepare('SELECT d.*,p.name product_name FROM flash_deals d JOIN products p ON p.id=d.product_id AND p.store_id=d.store_id WHERE d.store_id=? ORDER BY d.id DESC');$q->execute([$storeId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row)$row=$this->present($row);unset($row);return $rows; }
    public function get(int $id,int $storeId): array { $q=$this->db->prepare('SELECT d.*,p.name product_name FROM flash_deals d JOIN products p ON p.id=d.product_id AND p.store_id=d.store_id WHERE d.id=? AND d.store_id=?');$q->execute([$id,$storeId]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new \RuntimeException('Flash deal request not found',404);return $this->present($r); }
    public function submit(int $storeId,array $input): array {
        $v=FlashDealValidator::validate($input);$q=$this->db->prepare('SELECT id,name,price,published,hidden,archived FROM products WHERE id=? AND store_id=?');$q->execute([$v['product_id'],$storeId]);$p=$q->fetch(PDO::FETCH_ASSOC);
        if(!$p)throw new \RuntimeException('Product not found',404);if(!(int)$p['published']|| (int)$p['hidden']|| (int)$p['archived'])throw new \RuntimeException('Only published products can be submitted',409);
        if($v['price']>=(float)$p['price'])throw new \InvalidArgumentException('Deal price must be lower than the current product price');
        $q=$this->db->prepare("SELECT id FROM flash_deals WHERE store_id=? AND product_id=? AND status='Pending' LIMIT 1");$q->execute([$storeId,$v['product_id']]);if($q->fetchColumn())throw new \RuntimeException('This product already has a pending flash deal request',409);
        $q=$this->db->prepare('INSERT INTO flash_deals(store_id,product_id,price,original_price,starts_at,ends_at) VALUES(?,?,?,?,?,?)');$q->execute([$storeId,$v['product_id'],$v['price'],(float)$p['price'],$input['starts_at']??null,$input['ends_at']??null]);$id=(int)$this->db->lastInsertId();
        (new NotificationService($this->db))->create($storeId,'flash_deal_submitted','Flash deal submitted','Flash deal request for '.$p['name'].' was submitted.');return $this->get($id,$storeId);
    }
    public function cancel(int $id,int $storeId): array { $deal=$this->get($id,$storeId);if($deal['status']!=='Pending')throw new \RuntimeException('Only pending flash deal requests can be cancelled',409);$q=$this->db->prepare("UPDATE flash_deals SET status='Cancelled' WHERE id=? AND store_id=? AND status='Pending'");$q->execute([$id,$storeId]);if($q->rowCount()!==1)throw new \RuntimeException('Request status changed; reload and retry',409);(new NotificationService($this->db))->create($storeId,'flash_deal_cancelled','Flash deal cancelled','Flash deal request #'.$id.' was cancelled.');return $this->get($id,$storeId); }
    private function present(array $deal): array { $deal['request_id']=(int)$deal['id'];$deal['requested_price']=(float)$deal['price'];$deal['management_notes']=$deal['management_note']??null;$deal['request_date']=$deal['created_at']??null;return $deal; }
}
