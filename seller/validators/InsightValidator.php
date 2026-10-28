<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class InsightValidator {
    public static function period(mixed $value, int $default=12): int {
        if ($value === null || $value === '') return $default;
        $days=filter_var($value,FILTER_VALIDATE_INT);
        if ($days===false || $days<1 || $days>366) throw new \InvalidArgumentException('Period must be between 1 and 366 days');
        return (int)$days;
    }
    public static function productId(mixed $value): int { return FinanceValidator::id($value,'product'); }
}
