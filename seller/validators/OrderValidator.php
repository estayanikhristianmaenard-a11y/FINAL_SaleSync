<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class OrderValidator {
    public static function id(mixed $value): int { $id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new \InvalidArgumentException('A valid order id is required');return $id; }
}
