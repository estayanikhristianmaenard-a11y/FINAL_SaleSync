<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class PayoutValidator {
    public static function id(mixed $value): int { return FinanceValidator::id($value,'payout'); }
}
