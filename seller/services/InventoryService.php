<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class InventoryService {
    public function __construct(private PDO $db) {}
    public function available(int $id): int {
        $q=$this->db->prepare('SELECT stock-reserved FROM products WHERE id=?');$q->execute([$id]);$v=$q->fetchColumn();
        if($v===false)throw new \RuntimeException('Product not found',404);return (int)$v;
    }
    /** Atomically deduct units from stock after an order is confirmed. */
    public function deduct(int $id,int $quantity): void {
        $quantity=StockValidator::quantity($quantity);
        $q=$this->db->prepare('UPDATE products SET stock=stock-? WHERE id=? AND stock-reserved>=?');$q->execute([$quantity,$id,$quantity]);
        if($q->rowCount()!==1)throw new \RuntimeException('Insufficient available stock or product not found',409);
    }
    /** Add units after a return is approved. */
    public function restock(int $id,int $quantity): void {
        $quantity=StockValidator::quantity($quantity);
        $q=$this->db->prepare('UPDATE products SET stock=stock+? WHERE id=?');$q->execute([$quantity,$id]);
        if($q->rowCount()!==1)throw new \RuntimeException('Product not found',404);
    }
    /** Set a seller's on-hand quantity without reducing units reserved for paid orders. */
    public function setStock(int $id,int $storeId,int $quantity): int {
        if($quantity<0)throw new \InvalidArgumentException('Stock quantity cannot be negative');
        $find=$this->db->prepare('SELECT stock,reserved FROM products WHERE id=? AND store_id=?');$find->execute([$id,$storeId]);$current=$find->fetch(PDO::FETCH_ASSOC);
        if(!$current)throw new \RuntimeException('Product not found',404);
        if($quantity<(int)$current['reserved'])throw new \InvalidArgumentException('Stock cannot be lower than units reserved for orders');
        if($quantity===(int)$current['stock'])return (int)$current['stock'];
        $update=$this->db->prepare('UPDATE products SET stock=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND store_id=? AND stock>=reserved');$update->execute([$quantity,$id,$storeId]);
        if($update->rowCount()!==1)throw new \RuntimeException('Stock changed while it was being updated; reload and retry',409);
        return $quantity;
    }
}
