<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class FlashDealValidator {
    public static function validate(array $data): array {
        $id=filter_var($data['product_id']??$data['product']??null,FILTER_VALIDATE_INT);
        if($id===false||$id<1)throw new \InvalidArgumentException('A valid product is required');
        $price=$data['price']??null;if(!is_numeric($price)||(float)$price<=0)throw new \InvalidArgumentException('Deal price must be greater than zero');
        return ['product_id'=>$id,'price'=>(float)$price];
    }
}
