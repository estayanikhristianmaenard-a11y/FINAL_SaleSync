<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ProductValidator {
    private static function value(array $data,array $keys,mixed $default=null): mixed {
        foreach($keys as $key)if(array_key_exists($key,$data))return $data[$key];
        return $default;
    }
    private static function text(mixed $value,string $label,int $limit,bool $required=false): string {
        if($value===null)$value='';
        if(!is_string($value)&&!is_numeric($value))throw new \InvalidArgumentException($label.' must be text');
        $value=trim((string)$value);$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
        if($required&&$value==='')throw new \InvalidArgumentException($label.' is required');
        if($length>$limit)throw new \InvalidArgumentException($label.' is too long');
        return $value;
    }
    private static function jsonArray(mixed $value,string $label): array {
        if($value===null||$value==='')return [];
        if(is_string($value)){$value=json_decode($value,true);if(!is_array($value))throw new \InvalidArgumentException($label.' must be valid JSON');}
        if(!is_array($value))throw new \InvalidArgumentException($label.' must be a list or object');
        return $value;
    }
    public static function validate(array $data,bool $partial=false): array {
        $out=[];$keys=[
            'name'=>[['name','product_name'],'Product name',200,true],
            'sku'=>[['sku'],'SKU',100,true],
            'category'=>[['category'],'Category',120,true],
            'brand'=>[['brand'],'Brand',120,false],
            'description'=>[['description'],'Description',10000,true],
        ];
        foreach($keys as $column=>[$aliases,$label,$limit,$required]){
            $present=false;foreach($aliases as $alias)if(array_key_exists($alias,$data)){$present=true;break;}
            if(!$present){if(!$partial&&$required)throw new \InvalidArgumentException($label.' is required');continue;}
            $out[$column]=self::text(self::value($data,$aliases),$label,$limit,$required&&!$partial);
            if($required&&!$partial&&$out[$column]==='')throw new \InvalidArgumentException($label.' is required');
        }
        $priceKeys=['price','selling_price','sellingPrice'];$pricePresent=false;foreach($priceKeys as $key)if(array_key_exists($key,$data)){$pricePresent=true;break;}
        if($pricePresent){$price=self::value($data,$priceKeys);if($price===''||$price===null){if(!$partial)throw new \InvalidArgumentException('Price must be greater than zero');$out['price']=0.0;}elseif(!is_numeric($price)||(float)$price<0||(!$partial&&(float)$price<=0))throw new \InvalidArgumentException('Price must be greater than zero');else $out['price']=(float)$price;}
        elseif(!$partial)throw new \InvalidArgumentException('Price is required');
        $discountKeys=['discount_price','discountPrice'];$hasDiscount=false;foreach($discountKeys as $key)if(array_key_exists($key,$data)){$hasDiscount=true;break;}
        if($hasDiscount){$discount=self::value($data,$discountKeys);if($discount===''||$discount===null)$out['discount_price']=null;elseif(!is_numeric($discount)||(float)$discount<=0)throw new \InvalidArgumentException('Discount price must be greater than zero');else{$out['discount_price']=(float)$discount;$basis=$out['price']??(float)self::value($data,$priceKeys,0);if($basis>0&&$out['discount_price']>=$basis)throw new \InvalidArgumentException('Discount price must be below the selling price');}}
        $stockKeys=['stock_quantity','stockQuantity','stock'];$hasStock=false;foreach($stockKeys as $key)if(array_key_exists($key,$data)){$hasStock=true;break;}
        if($hasStock){$raw=self::value($data,$stockKeys);if($raw===''||$raw===null)$raw=0;$stock=filter_var($raw,FILTER_VALIDATE_INT);if($stock===false||$stock<0)throw new \InvalidArgumentException('Stock quantity must be a non-negative whole number');$out['stock']=(int)$stock;}elseif(!$partial)$out['stock']=0;
        $thresholdKeys=['low_stock_threshold','lowStockThreshold'];$hasThreshold=false;foreach($thresholdKeys as $key)if(array_key_exists($key,$data)){$hasThreshold=true;break;}
        if($hasThreshold){$threshold=filter_var(self::value($data,$thresholdKeys),FILTER_VALIDATE_INT);if($threshold===false||$threshold<0)throw new \InvalidArgumentException('Low stock threshold must be a non-negative whole number');$out['low_stock_threshold']=(int)$threshold;}
        if(array_key_exists('status',$data)){if(!in_array($data['status'],['Draft','Published'],true))throw new \InvalidArgumentException('Seller product status must be Draft or Published');$out['status']=$data['status'];}
        foreach(['thumbnail'=>[['thumbnail'],'Thumbnail',1000],'gallery_images'=>[['gallery_images','galleryImages'],'Gallery images',1000]] as $column=>[$aliases,$label,$limit]){
            $present=false;foreach($aliases as $alias)if(array_key_exists($alias,$data)){$present=true;break;}if(!$present)continue;
            if($column==='thumbnail')$out[$column]=self::text(self::value($data,$aliases),$label,$limit);
            else{$items=self::jsonArray(self::value($data,$aliases),$label);if(count($items)>5)throw new \InvalidArgumentException('A product may contain at most five gallery images');foreach($items as $item)if(!is_string($item)||strlen($item)>$limit)throw new \InvalidArgumentException('Invalid product gallery image path');$out[$column]=array_values($items);}
        }
        foreach(['specifications','variants','images'] as $key)if(array_key_exists($key,$data))$out[$key]=self::jsonArray($data[$key],ucfirst($key));
        if(isset($out['variants'])&&count($out['variants'])>200)throw new \InvalidArgumentException('A product may have at most 200 variant options');
        if(array_key_exists('specifications',$out)&&count($out['specifications'])>100)throw new \InvalidArgumentException('Too many product specifications');
        if(array_key_exists('store_category_id',$data)||array_key_exists('storeCategoryId',$data))$out['store_category_id']=self::text(self::value($data,['store_category_id','storeCategoryId']),'Store collection',100);
        return $out;
    }
    public static function draft(array $data): array { return self::validate($data,true); }
}
