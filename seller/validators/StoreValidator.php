<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class StoreValidator {
    private const FIELDS=['name','description','contact_number','email','address'];
    private static function length(string $value): int { return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value); }
    public static function validate(array $data): array {
        $fields=[];
        foreach(self::FIELDS as $key) {
            if(!array_key_exists($key,$data)) continue;
            if(!is_string($data[$key]) && !is_numeric($data[$key])) throw new \InvalidArgumentException('Invalid '.$key);
            $value=trim((string)$data[$key]);
            if($key==='name' && self::length($value)<3) throw new \InvalidArgumentException('Store name must be at least 3 characters');
            if($key==='name' && self::length($value)>255) throw new \InvalidArgumentException('Store name must not exceed 255 characters');
            if($key==='description' && $value==='') throw new \InvalidArgumentException('Store description is required');
            if($key==='description' && self::length($value)>5000) throw new \InvalidArgumentException('Store description must not exceed 5000 characters');
            if($key==='contact_number' && $value==='') throw new \InvalidArgumentException('Contact number is required');
            if($key==='email' && ($value==='' || !filter_var($value,FILTER_VALIDATE_EMAIL))) throw new \InvalidArgumentException('A valid email address is required');
            if($key==='address' && $value==='') throw new \InvalidArgumentException('Store address is required');
            if(in_array($key,['email','contact_number','address'],true) && self::length($value)>255) throw new \InvalidArgumentException(ucfirst(str_replace('_',' ',$key)).' is too long');
            $fields[$key]=$value;
        }
        return $fields;
    }
    public static function fullInformation(array $data): array {
        foreach(['name','description','contact_number','email','address'] as $key) {
            if(!array_key_exists($key,$data)) throw new \InvalidArgumentException(ucfirst(str_replace('_',' ',$key)).' is required');
        }
        return self::validate($data);
    }
}
