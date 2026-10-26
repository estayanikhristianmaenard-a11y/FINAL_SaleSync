<?php
declare(strict_types=1);

namespace SaleSync\Payments;

interface Gateway
{
    public function ready(): bool;
    public function create(array $order): array;
    public function retrieve(string $reference): array;
}
