<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class StoreService {
    private StoreProfile $store;
    public function __construct(private PDO $db,private int $sellerId) { $this->store=new StoreProfile($db,$sellerId); }
    public function profile(): array { return $this->store->ownedProfile(); }
    public function status(): array {
        $profile=$this->profile();
        return ['store_id'=>(int)$profile['store_id'],'status'=>$profile['status']];
    }
    public function updateProfile(array $data): array { return $this->update($data,false); }
    public function updateStoreInfo(array $data): array { return $this->update($data,true); }
    private function update(array $data,bool $requireAll): array {
        $fields=$requireAll?StoreValidator::fullInformation($data):StoreValidator::validate($data);
        if(!$fields) throw new \InvalidArgumentException('No store fields supplied');
        $this->db->beginTransaction();
        try {
            $before=$this->profile();
            $this->store->updateOwned($fields);
            $storeId=(int)$before['store_id'];$notifications=new NotificationService($this->db);
            if(array_intersect(['name','description'],$fields)) $notifications->create($storeId,'store_profile_updated','Store Profile Updated','Your store profile was updated.');
            if(array_intersect(['contact_number','email','address'],$fields)) $notifications->create($storeId,'store_information_changed','Store Information Changed','Your store contact information was updated.');
            $this->db->commit();
        } catch (\Throwable $error) {
            if($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $this->profile();
    }
    public function upload(string $asset,array $file): array {
        $before=$this->profile(); // Confirms this seller owns a store before accepting a file.
        $uploaded=(new UploadService())->storeImage($file,$asset);
        try {
            $this->db->beginTransaction();
            $this->store->updateOwnedAsset($asset,$uploaded['path']);
            $title=$asset==='logo'?'Store Logo Updated':'Store Banner Updated';
            (new NotificationService($this->db))->create((int)$before['store_id'],'store_'.$asset.'_updated',$title,'Your store '.$asset.' was updated.');
            $this->db->commit();
        } catch (\Throwable $error) {
            if($this->db->inTransaction()) $this->db->rollBack();
            if(is_file($uploaded['absolute_path'])) @unlink($uploaded['absolute_path']);
            throw $error;
        }
        return $this->profile();
    }
}
