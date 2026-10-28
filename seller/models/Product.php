<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Product extends BaseModel {
    private function decode(array $row): array {
        foreach(['images'=>[],'gallery_images'=>[],'specifications'=>[],'variants'=>[]] as $key=>$fallback){$value=json_decode((string)($row[$key]??''),true);$row[$key]=is_array($value)?$value:$fallback;}
        $row['id']=(int)$row['id'];$row['product_id']=$row['id'];$row['seller_id']=(int)($row['seller_id']??$this->sellerId);$row['store_id']=(int)$row['store_id'];
        $row['price']=(float)$row['price'];$row['stock']=(int)$row['stock'];$row['stock_quantity']=$row['stock'];$row['reserved']=(int)($row['reserved']??0);$row['low_stock_threshold']=(int)($row['low_stock_threshold']??5);
        $row['published']=(int)$row['published'];$row['archived']=(int)$row['archived'];$row['hidden']=(int)$row['hidden'];
        $row['discount_price']=$row['discount_price']===null?null:(float)$row['discount_price'];$row['thumbnail']??=null;
        $row['status']=$row['hidden']?'Hidden by Management':($row['archived']?'Archived':($row['published']?'Published':'Draft'));
        return $row;
    }
    public function all(): array {
        $rows=$this->rows('SELECT p.*,s.seller_id FROM products p JOIN stores s ON s.id=p.store_id WHERE p.store_id=? AND s.seller_id=? ORDER BY p.id DESC',[$this->storeId(),$this->sellerId]);
        return array_map(fn(array $row):array=>$this->decode($row),$rows);
    }
    public function find(int $id): array { return $this->decode($this->owned('products',$id)); }
    public function create(int $storeId,array $data): int {
        $columns=['seller_id','store_id','name','sku','category','brand','description','price','discount_price','stock','low_stock_threshold','thumbnail','gallery_images','specifications','variants','images','store_category_id','published'];
        $values=[$this->sellerId,$storeId,$data['name'],$data['sku'],$data['category']??null,$data['brand']??null,$data['description'],$data['price']??0,$data['discount_price']??null,$data['stock']??0,$data['low_stock_threshold']??5,$data['thumbnail']??null,$this->json($data['gallery_images']??[]),$this->json($data['specifications']??[]),$this->json($data['variants']??[]),$this->json($data['images']??[]),$data['store_category_id']?:null,($data['status']??'Draft')==='Published'?1:0];
        $this->run('INSERT INTO products('.implode(',',$columns).',archived,updated_at) VALUES('.implode(',',array_fill(0,count($columns),'?')).",0,CURRENT_TIMESTAMP)",$values);
        return (int)$this->db->lastInsertId();
    }
    public function update(int $id,array $data): int {
        $map=['name'=>'name','sku'=>'sku','category'=>'category','brand'=>'brand','description'=>'description','price'=>'price','discount_price'=>'discount_price','stock'=>'stock','low_stock_threshold'=>'low_stock_threshold','thumbnail'=>'thumbnail','gallery_images'=>'gallery_images','specifications'=>'specifications','variants'=>'variants','images'=>'images','store_category_id'=>'store_category_id'];$set=[];$values=[];
        foreach($map as $key=>$column)if(array_key_exists($key,$data)){$set[]=$column.'=?';$values[]=$key==='gallery_images'||$key==='specifications'||$key==='variants'||$key==='images'?$this->json($data[$key]):$data[$key];}
        if(array_key_exists('status',$data)){$set[]='published=?';$values[]=$data['status']==='Published'?1:0;}
        if(!$set)return 0;$set[]='updated_at=CURRENT_TIMESTAMP';$values[]=$id;$values[]=$this->storeId();$values[]=$this->sellerId;
        return $this->run('UPDATE products SET '.implode(',',$set).' WHERE id=? AND store_id=? AND seller_id=?',$values);
    }
    public function setStatus(int $id,string $status): int {
        if(!in_array($status,['Draft','Published','Archived'],true))throw new \InvalidArgumentException('Invalid product status');
        $archived=$status==='Archived'?1:0;$published=$status==='Published'?1:0;
        return $this->run('UPDATE products SET archived=?,published=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND store_id=? AND seller_id=?',[$archived,$published,$id,$this->storeId(),$this->sellerId]);
    }
    public function delete(int $id): int { return $this->run('DELETE FROM products WHERE id=? AND store_id=? AND seller_id=?',[$id,$this->storeId(),$this->sellerId]); }
    private function json(array $value): string { return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR); }
}
