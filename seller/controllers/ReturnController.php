<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ReturnController extends Controller {
    public function getReturns($id=null,array $d=[]): array { return (new ReturnService($this->db))->all($this->storeId()); }
    public function index($id=null,array $d=[]): array { return $this->getReturns($id,$d); }
    public function getReturn($id=null,array $d=[]): array { return (new ReturnService($this->db))->get($this->requiredId($id??$d['return_id']??null),$this->storeId()); }
    public function reviewReturn($id=null,array $d=[]): array {
        $id=$this->requiredId($id??$d['return_id']??null);$store=$this->storeId();$seller=$this->sellerId;
        $record=(new ReturnService($this->db))->get($id,$store);if($record['status']!=='Requested')throw new \RuntimeException('Only requested returns can be reviewed',409);
        $decision=ReturnValidator::decision($d);$notes=trim((string)($d['notes']??$d['reason']??''));$this->db->beginTransaction();
        try {
            $nextStatus=$decision==='Approved'&&($record['solution']??'')==='Refund only'?'Refund Pending':$decision;
            $q=$this->db->prepare('UPDATE returns SET status=?,seller_notes=? WHERE id=? AND store_id=? AND status=\'Requested\'');$q->execute([$nextStatus,$notes,$id,$store]);
            if($q->rowCount()!==1)throw new \RuntimeException('Return status changed; reload and retry',409);
            (new ReturnService($this->db))->appendHistory($id,$decision,$notes,$seller);
            if($nextStatus!==$decision)(new ReturnService($this->db))->appendHistory($id,$nextStatus,'Seller accepted refund-only request',$seller);
            $this->db->commit();return $this->getReturn($id);
        } catch(\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
    }
    public function review($id=null,array $d=[]): array { return $this->reviewReturn($id,$d); }
    public function receiveReturn($id=null,array $d=[]): array {
        $id=$this->requiredId($id??$d['return_id']??null);$store=$this->storeId();$seller=$this->sellerId;
        $record=(new ReturnService($this->db))->get($id,$store);if($record['status']!=='Approved')throw new \RuntimeException('Only approved returns can be marked received',409);
        return $this->transaction(function()use($id,$store,$seller){
            $q=$this->db->prepare("UPDATE returns SET status='Returned' WHERE id=? AND store_id=? AND status='Approved'");$q->execute([$id,$store]);if($q->rowCount()!==1)throw new \RuntimeException('Return status changed; reload and retry',409);
            $q=$this->db->prepare('INSERT INTO return_inspections(return_id,received_at) VALUES(?,CURRENT_TIMESTAMP) ON CONFLICT(return_id) DO UPDATE SET received_at=COALESCE(return_inspections.received_at,CURRENT_TIMESTAMP)');$q->execute([$id]);
            (new ReturnService($this->db))->appendHistory($id,'Returned','Item received by seller',$seller);
            (new NotificationService($this->db))->create($store,'return_received','Return received','Returned item received for return #'.$id.'.');return $this->getReturn($id);
        });
    }
    public function submitInspection($id=null,array $d=[]): array {
        $id=$this->requiredId($id??$d['return_id']??null);$outcome=InspectionValidator::outcome($d);$notes=InspectionValidator::notes($d['notes']??'');
        return (new InspectionService($this->db))->submitFindings($id,$this->storeId(),$outcome,$notes,$this->sellerId);
    }
    public function getHistory($id=null,array $d=[]): array { $id=$this->requiredId($id??$d['return_id']??null);return (new ReturnService($this->db))->history($id,$this->storeId()); }
    public function workflow($id=null,array $d=[]): array {
        return match((string)($d['action']??'')){
            'review','reviewReturn'=>$this->reviewReturn($id,$d),'receiveReturn'=>$this->receiveReturn($id,$d),
            'submitInspection'=>$this->submitInspection($id,$d),'getHistory'=>$this->getHistory($id,$d),
            default=>throw new \InvalidArgumentException('Unknown return action')
        };
    }
}
