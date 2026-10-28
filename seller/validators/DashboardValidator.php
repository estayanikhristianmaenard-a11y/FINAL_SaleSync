<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class DashboardValidator {
    public static function limit(mixed $value,int $default=20,int $maximum=100): int {
        if($value===null||$value==='') return $default;
        $limit=filter_var($value,FILTER_VALIDATE_INT);
        if($limit===false||$limit<1||$limit>$maximum) throw new \InvalidArgumentException('Limit is out of range');
        return (int)$limit;
    }
    public static function days(mixed $value,int $default=12): int {
        if($value===null||$value==='') return $default;
        $days=filter_var($value,FILTER_VALIDATE_INT);
        if($days===false||$days<1||$days>366) throw new \InvalidArgumentException('Days must be between 1 and 366');
        return (int)$days;
    }
}
