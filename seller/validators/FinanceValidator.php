<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class FinanceValidator {
    public static function id(mixed $value,string $label='Record'): int { $id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new \InvalidArgumentException('A valid '.$label.' ID is required');return $id; }
}
