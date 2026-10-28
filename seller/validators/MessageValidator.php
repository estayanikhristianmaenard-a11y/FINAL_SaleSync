<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class MessageValidator {
    public static function body(array $data): string {
        $value=$data['body']??$data['content']??null;
        if(!is_string($value)) throw new \InvalidArgumentException('Message content is required');
        $value=trim($value);
        $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
        if($length<1) throw new \InvalidArgumentException('Message content cannot be empty');
        if($length>1000) throw new \InvalidArgumentException('Message content must not exceed 1000 characters');
        return $value;
    }
}
