<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class InspectionValidator {
    private const OUTCOMES=['Item Received In Good Condition','Item Damaged','Wrong Item Returned','Missing Parts','Used Item','Defective Product Confirmed','Return Rejected'];
    public static function outcome(array $data): string {
        $value=trim((string)($data['outcome']??$data['condition']??''));
        $aliases=['Restock good condition'=>'Item Received In Good Condition','Resellable'=>'Item Received In Good Condition','Do not restock'=>'Item Damaged','Damaged / not resellable'=>'Item Damaged'];
        $value=$aliases[$value]??$value;
        if(!in_array($value,self::OUTCOMES,true))throw new \InvalidArgumentException('Select a valid inspection outcome');
        return $value;
    }
    public static function notes(mixed $value): string { $notes=trim((string)($value??''));if(strlen($notes)<10)throw new \InvalidArgumentException('Describe the inspection findings in at least 10 characters');if(strlen($notes)>5000)throw new \InvalidArgumentException('Inspection notes are too long');return $notes; }
}
