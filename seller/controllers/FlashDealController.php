<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class FlashDealController extends Controller {
    public function getRequests($id=null,array $d=[]): array { return (new FlashDealService($this->db))->all($this->storeId()); }
    public function index($id=null,array $d=[]): array { return $this->getRequests($id,$d); }
    public function getRequest($id=null,array $d=[]): array { return (new FlashDealService($this->db))->get($this->requiredId($id??$d['request_id']??null),$this->storeId()); }
    public function status($id=null,array $d=[]): array { return $this->getRequest($id,$d); }
    public function submitRequest($id=null,array $d=[]): array { return (new FlashDealService($this->db))->submit($this->storeId(),$d); }
    public function submit($id=null,array $d=[]): array { return $this->submitRequest($id,$d); }
    public function cancelRequest($id=null,array $d=[]): array { return (new FlashDealService($this->db))->cancel($this->requiredId($id??$d['request_id']??null),$this->storeId()); }
    public function cancel($id=null,array $d=[]): array { return $this->cancelRequest($id,$d); }
}
