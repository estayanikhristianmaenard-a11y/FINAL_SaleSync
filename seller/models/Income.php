<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Income extends BaseModel {
    public function summary(): array { return (new FinanceService($this->db))->summary($this->storeId()); }
}
