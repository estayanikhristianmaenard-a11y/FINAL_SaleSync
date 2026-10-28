<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class StoreController extends Controller {
    private function storeService(): StoreService { return new StoreService($this->db,$this->sellerId); }
    public function getProfile($id=null,array $data=[]): array { return $this->storeService()->profile(); }
    public function updateProfile($id=null,array $data=[]): array { return $this->storeService()->updateProfile($data); }
    public function updateStoreInfo($id=null,array $data=[]): array { return $this->storeService()->updateStoreInfo($data); }
    public function uploadLogo($id=null,array $data=[]): array { return $this->storeService()->upload('logo',$_FILES['logo']??$_FILES['image']??[]); }
    public function uploadBanner($id=null,array $data=[]): array { return $this->storeService()->upload('banner',$_FILES['banner']??$_FILES['image']??[]); }
    public function getStoreStatus($id=null,array $data=[]): array { return $this->storeService()->status(); }
}
