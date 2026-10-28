<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class VoucherController extends Controller {
    public function getVouchers($id=null,array $d=[]): array { return (new VoucherService($this->db))->all($this->storeId()); }
    public function index($id=null,array $d=[]): array { return $this->getVouchers($id,$d); }
    public function getVoucher($id=null,array $d=[]): array { return (new VoucherService($this->db))->get($this->requiredId($id??$d['voucher_id']??null),$this->storeId()); }
    public function createVoucher($id=null,array $d=[]): array { return (new VoucherService($this->db))->create($this->storeId(),$d); }
    public function create($id=null,array $d=[]): array { return $this->createVoucher($id,$d); }
    public function updateVoucher($id=null,array $d=[]): array { return (new VoucherService($this->db))->update($this->requiredId($id??$d['voucher_id']??null),$this->storeId(),$d); }
    public function update($id=null,array $d=[]): array { return $this->updateVoucher($id,$d); }
    public function disableVoucher($id=null,array $d=[]): array { return (new VoucherService($this->db))->disable($this->requiredId($id??$d['voucher_id']??null),$this->storeId()); }
    public function disable($id=null,array $d=[]): array { return $this->disableVoucher($id,$d); }
    public function enableVoucher($id=null,array $d=[]): array { return (new VoucherService($this->db))->enable($this->requiredId($id??$d['voucher_id']??null),$this->storeId()); }
    public function deleteVoucher($id=null,array $d=[]): array { return (new VoucherService($this->db))->delete($this->requiredId($id??$d['voucher_id']??null),$this->storeId()); }
    public function voucherUsage($id=null,array $d=[]): array { return (new VoucherService($this->db))->usage($this->requiredId($id??$d['voucher_id']??null),$this->storeId()); }
    public function getUsage($id=null,array $d=[]): array { return $this->voucherUsage($id,$d); }
}
