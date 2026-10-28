<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class InspectionController extends Controller {
    public function createInspection($id=null,array $d=[]): array { $returnId=$this->requiredId($id??$d['return_id']??null);return (new InspectionService($this->db))->create($returnId,$this->storeId()); }
    public function saveNotes($id=null,array $d=[]): array { $returnId=$this->requiredId($id??$d['return_id']??null);return (new InspectionService($this->db))->saveNotes($returnId,$this->storeId(),InspectionValidator::notes($d['notes']??'')); }
    public function uploadEvidence($id=null,array $d=[]): array { $returnId=$this->requiredId($id??$d['return_id']??null);return (new InspectionService($this->db))->uploadEvidence($returnId,$this->storeId(),$this->sellerId,$_FILES['evidence']??[]); }
    public function submitFindings($id=null,array $d=[]): array { $returnId=$this->requiredId($id??$d['return_id']??null);return (new InspectionService($this->db))->submitFindings($returnId,$this->storeId(),InspectionValidator::outcome($d),InspectionValidator::notes($d['notes']??''),$this->sellerId); }
    public function getInspection($id=null,array $d=[]): array { $returnId=$this->requiredId($id??$d['return_id']??null);return (new InspectionService($this->db))->get($returnId,$this->storeId()); }
}
