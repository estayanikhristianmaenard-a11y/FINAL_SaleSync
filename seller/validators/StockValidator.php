<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class StockValidator {
    public static function quantity(mixed $value,string $label='Quantity',bool $allowZero=false): int {
        $number=filter_var($value,FILTER_VALIDATE_INT);
        if ($number===false || ($allowZero?$number<0:$number<1)) throw new \InvalidArgumentException($label.($allowZero?' must be a non-negative integer':' must be a positive integer'));
        return $number;
    }
}
