<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class InspectionService {
    public function __construct(private PDO $db) {}
    public function get(int $returnId,int $storeId): array {
        (new ReturnService($this->db))->get($returnId,$storeId);
        $q=$this->db->prepare('SELECT i.* FROM return_inspections i JOIN returns r ON r.id=i.return_id WHERE i.return_id=? AND r.store_id=?');$q->execute([$returnId,$storeId]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new \RuntimeException('Inspection not found',404);return $r;
    }
    public function create(int $returnId,int $storeId): array {
        $r=(new ReturnService($this->db))->get($returnId,$storeId);
        if($r['status']!=='Returned')throw new \RuntimeException('The returned item must be received before inspection',409);
        $q=$this->db->prepare('INSERT INTO return_inspections(return_id,received_at) VALUES(?,?) ON CONFLICT(return_id) DO NOTHING');$q->execute([$returnId,$r['received_at']??date('c')]);
        return $this->get($returnId,$storeId);
    }
    public function saveNotes(int $returnId,int $storeId,string $notes): array {
        $inspection=$this->create($returnId,$storeId);
        $q=$this->db->prepare('UPDATE return_inspections SET notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$q->execute([$notes,(int)$inspection['id']]);return $this->get($returnId,$storeId);
    }
    public function uploadEvidence(int $returnId,int $storeId,int $sellerId,array $upload): array {
        $return=(new ReturnService($this->db))->get($returnId,$storeId);
        if(!in_array($return['status'],['Requested','Returned'],true))throw new \RuntimeException('Evidence can only be attached to an active return',409);
        $files=[];
        if(isset($upload['name'])&&is_array($upload['name'])){
            foreach(array_keys($upload['name']) as $i)$files[]=['name'=>$upload['name'][$i]??'','type'=>$upload['type'][$i]??'','tmp_name'=>$upload['tmp_name'][$i]??'','error'=>$upload['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$upload['size'][$i]??0];
        } elseif(isset($upload['name'])) $files[]=$upload;
        $files=array_values(array_filter($files,fn($f)=>($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE));
        if(!$files)throw new \InvalidArgumentException('Select at least one evidence image');
        $existing=(new ReturnService($this->db))->evidence($returnId,$storeId);
        if(count($existing)+count($files)>3)throw new \InvalidArgumentException('Attach no more than three evidence images to a return');
        $saved=[];$paths=[];$this->db->beginTransaction();
        try {
            foreach($files as $file){$image=(new UploadService())->returnEvidence($file);$paths[]=$image['path'];$q=$this->db->prepare('INSERT INTO return_evidence(return_id,store_id,seller_id,path,mime_type) VALUES(?,?,?,?,?)');$q->execute([$returnId,$storeId,$sellerId,$image['path'],$image['mime_type']]);$saved[]=['id'=>(int)$this->db->lastInsertId(),'return_id'=>$returnId,'path'=>$image['path'],'src'=>'/seller/'.$image['path'],'name'=>basename($image['path']),'mime_type'=>$image['mime_type']];}
            $this->db->commit();return $saved;
        } catch(\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();foreach($paths as $path){$full=UPLOAD_DIR.'/'.substr($path,strlen('uploads/'));if(is_file($full))@unlink($full);}throw $e; }
    }
    public function submitFindings(int $returnId,int $storeId,string $outcome,string $notes,int $sellerId): array {
        $r=(new ReturnService($this->db))->get($returnId,$storeId);
        if($r['status']!=='Returned')throw new \RuntimeException('Only received returns can be inspected',409);
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('INSERT INTO return_inspections(return_id,outcome,notes,received_at,inspected_at,updated_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(return_id) DO UPDATE SET outcome=excluded.outcome,notes=excluded.notes,inspected_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP');
            $q->execute([$returnId,$outcome,$notes,$r['received_at']??date('c')]);
            $u=$this->db->prepare("UPDATE returns SET status='Refund Pending' WHERE id=? AND store_id=? AND status='Returned'");$u->execute([$returnId,$storeId]);
            if($u->rowCount()!==1)throw new \RuntimeException('Return status changed; reload and retry',409);
            if($outcome==='Item Received In Good Condition')(new InventoryService($this->db))->restock((int)$r['product_id'],(int)$r['quantity']);
            (new ReturnService($this->db))->appendHistory($returnId,'Refund Pending',$outcome.($notes!==''?' · '.$notes:''),$sellerId);
            (new NotificationService($this->db))->create($storeId,'return_inspection_submitted','Inspection submitted','Inspection findings submitted for return #'.$returnId.' for Management review.');
            $this->db->commit();return $this->get($returnId,$storeId);
        } catch(\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
    }
}
