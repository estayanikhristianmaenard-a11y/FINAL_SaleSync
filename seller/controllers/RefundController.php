<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class RefundController extends Controller {
    public function createRefund($id=null,array $d=[]): array { $orderId=OrderValidator::id($id??$d['order_id']??null);return (new RefundService($this->db))->createForOrder($orderId,$this->storeId(),(string)($d['reason']??'')); }
    public function approveRefund($id=null,array $d=[]): array { return (new RefundService($this->db))->decide($this->requiredId($id??$d['refund_id']??null),$this->storeId(),true); }
    public function rejectRefund($id=null,array $d=[]): array { return (new RefundService($this->db))->decide($this->requiredId($id??$d['refund_id']??null),$this->storeId(),false); }
    public function getRefund($id=null,array $d=[]): array { return (new RefundService($this->db))->get($this->requiredId($id??$d['refund_id']??null),$this->storeId()); }
    public function getRefunds($id=null,array $d=[]): array { return $this->rows('SELECT * FROM refunds WHERE store_id=? ORDER BY id DESC',[$this->storeId()]); }
}
