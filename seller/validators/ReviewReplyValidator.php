<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ReviewReplyValidator {
    public static function reply(array $data): string {
        $value=$data['reply']??$data['seller_reply']??null;
        if(!is_string($value)) throw new \InvalidArgumentException('A seller reply is required');
        $value=trim($value);
        $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
        if($length<1) throw new \InvalidArgumentException('A seller reply cannot be empty');
        if($length>5000) throw new \InvalidArgumentException('A seller reply must not exceed 5000 characters');
        return $value;
    }
}
