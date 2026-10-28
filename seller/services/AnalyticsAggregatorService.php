<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class AnalyticsAggregatorService {
    public function __construct(private PDO $db,private int $sellerId) {}
    public function metrics(): array { return (new DashboardStatistic($this->db,$this->sellerId))->aggregate(); }
}
