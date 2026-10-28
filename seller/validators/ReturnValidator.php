<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ReturnValidator {
    public static function decision(array $data): string {
        $decision=trim((string)($data['decision']??''));
        if(!in_array($decision,['Approved','Rejected'],true))throw new \InvalidArgumentException('Decision must be Approved or Rejected');
        $notes=trim((string)($data['notes']??$data['reason']??''));
        if(strlen($notes)<10)throw new \InvalidArgumentException('Provide an explanation of at least 10 characters');
        if(strlen($notes)>5000)throw new \InvalidArgumentException('Explanation is too long');
        return $decision;
    }
}
