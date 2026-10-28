<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Store extends BaseModel {
    public function profile(): array { return (new StoreProfile($this->db,$this->sellerId))->ownedProfile(); }
}
