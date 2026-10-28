<?php
declare(strict_types=1);
namespace SaleSync\Seller;
abstract class Controller extends BaseModel {
    protected function input(array $d,string $key,mixed $default=null): mixed { return $d[$key]??$default; }
    protected function transaction(callable $fn): mixed { $this->db->beginTransaction(); try {$v=$fn();$this->db->commit();return $v;} catch (\Throwable $e) {$this->db->rollBack();throw $e;} }
}
