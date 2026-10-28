<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Inspection extends BaseModel {
    public function findForReturn(int $returnId): array {
        $q=$this->db->prepare('SELECT i.* FROM return_inspections i JOIN returns r ON r.id=i.return_id WHERE i.return_id=? AND r.store_id=(SELECT id FROM stores WHERE seller_id=?)');
        $q->execute([$returnId,$this->sellerId]);$inspection=$q->fetch(\PDO::FETCH_ASSOC);if(!$inspection)throw new \RuntimeException('Inspection not found',404);return $inspection;
    }
}
