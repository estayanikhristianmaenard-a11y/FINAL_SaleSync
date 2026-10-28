<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;
abstract class BaseModel {
    public function __construct(protected PDO $db, protected int $sellerId) {}
    protected function storeId(): int { $q=$this->db->prepare('SELECT id FROM stores WHERE seller_id=?'); $q->execute([$this->sellerId]); $id=$q->fetchColumn(); if (!$id) throw new \RuntimeException('Seller store was not found',404); return (int)$id; }
    protected function rows(string $sql,array $args=[]): array { $q=$this->db->prepare($sql); $q->execute($args); return $q->fetchAll(); }
    protected function one(string $sql,array $args=[]): ?array { $q=$this->db->prepare($sql); $q->execute($args); $r=$q->fetch(); return $r?:null; }
    protected function run(string $sql,array $args=[]): int { $q=$this->db->prepare($sql); $q->execute($args); return $q->rowCount(); }
    protected function owned(string $table,int $id): array { $r=$this->one("SELECT * FROM {$table} WHERE id=? AND store_id=?",[$id,$this->storeId()]); if (!$r) throw new \RuntimeException('Resource not found',404); return $r; }
    protected function requiredId(mixed $id): int { if (!filter_var($id,FILTER_VALIDATE_INT) || (int)$id<1) throw new \InvalidArgumentException('A valid id is required'); return (int)$id; }
}
