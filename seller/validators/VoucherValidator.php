<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class VoucherValidator {
    public static function validate(array $data): array {
        $name=trim((string)($data['name']??''));if($name==='')throw new \InvalidArgumentException('Voucher name is required');
        if(strlen($name)>120)throw new \InvalidArgumentException('Voucher name is too long');
        $code=strtoupper(trim((string)($data['code']??'')));
        if($code===''||strlen($code)>64||!preg_match('/^[A-Z0-9_-]+$/',$code))throw new \InvalidArgumentException('Enter a valid voucher code');
        if(($data['scope']??'shop')!=='shop')throw new \InvalidArgumentException('Seller vouchers must use shop funding');
        $type=(string)($data['discount_type']??$data['kind']??'fixed');
        if(!in_array($type,['fixed','percent'],true))throw new \InvalidArgumentException('Discount type must be fixed or percent');
        $discount=$data['discount']??$data['value']??null;
        if(!is_numeric($discount)||(float)$discount<=0)throw new \InvalidArgumentException('Discount value must be greater than zero');
        $discount=(float)$discount;if($type==='percent'&&$discount>100)throw new \InvalidArgumentException('Percentage discount cannot exceed 100');
        $minimum=$data['min_spend']??$data['min']??0;
        if(!is_numeric($minimum)||(float)$minimum<0)throw new \InvalidArgumentException('Minimum spend cannot be negative');
        $limit=filter_var($data['usage_limit']??$data['usageLimit']??null,FILTER_VALIDATE_INT);
        if($limit===false||$limit<1)throw new \InvalidArgumentException('Usage limit must be a positive integer');
        $max=$data['max_discount']??$data['max']??$discount;
        if(!is_numeric($max)||(float)$max<=0)throw new \InvalidArgumentException('Maximum discount must be greater than zero');
        $start=self::date($data['starts_at']??$data['start']??null,'Start date',false);
        $end=self::date($data['ends_at']??$data['expires']??null,'Expiry date',true);
        if(strtotime($end)<=strtotime($start))throw new \InvalidArgumentException('Expiry date must be later than start date');
        return ['name'=>$name,'code'=>$code,'scope'=>'shop','discount_type'=>$type,'discount'=>$discount,'max_discount'=>(float)$max,'min_spend'=>(float)$minimum,'usage_limit'=>$limit,'starts_at'=>$start,'ends_at'=>$end];
    }
    private static function date(mixed $value,string $label,bool $endOfDay): string {
        $value=trim((string)($value??''));if($value==='')throw new \InvalidArgumentException($label.' is required');
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))$value.=$endOfDay?' 23:59:59':' 00:00:00';
        try{$date=new \DateTimeImmutable($value);}catch(\Throwable){throw new \InvalidArgumentException($label.' is invalid');}
        return $date->format('Y-m-d H:i:s');
    }
}
