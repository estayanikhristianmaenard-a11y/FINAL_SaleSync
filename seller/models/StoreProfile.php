<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class StoreProfile extends BaseModel {
    public function ownedProfile(): array {
        $profile=$this->one('SELECT id AS store_id,id,name,description,logo,banner,contact_number,email,address,status,rating,created_at,updated_at FROM stores WHERE seller_id=?',[$this->sellerId]);
        if(!$profile) throw new \RuntimeException('Seller store was not found',404);
        return $profile;
    }
    public function updateOwned(array $fields): void {
        $allowed=['name','description','contact_number','email','address'];
        $fields=array_intersect_key($fields,array_flip($allowed));
        if(!$fields) throw new \InvalidArgumentException('No store fields supplied');
        $assignments=array_map(static fn(string $field): string=>$field.'=?',array_keys($fields));
        $this->run('UPDATE stores SET '.implode(',',$assignments).',updated_at=CURRENT_TIMESTAMP WHERE seller_id=?',[...array_values($fields),$this->sellerId]);
    }
    public function updateOwnedAsset(string $asset,string $path): void {
        if(!in_array($asset,['logo','banner'],true)) throw new \InvalidArgumentException('Unsupported store image type');
        $this->run('UPDATE stores SET '.$asset.'=?,updated_at=CURRENT_TIMESTAMP WHERE seller_id=?',[$path,$this->sellerId]);
    }
}
