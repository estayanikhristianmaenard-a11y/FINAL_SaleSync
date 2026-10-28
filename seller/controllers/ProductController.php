<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class ProductController extends Controller {
    private Product $products;
    private UploadService $uploads;
    public function __construct(PDO $db,int $sellerId){parent::__construct($db,$sellerId);$this->products=new Product($db,$sellerId);$this->uploads=new UploadService();}
    private function productId($id,array $data=[]): int {return $this->requiredId($id??$data['product_id']??$data['id']??null);}
    private function removeUploads(array $paths): void {foreach($paths as $path)if(is_file($path))@unlink($path);}
    private function verifyImagePath(string $path): void {
        $pattern='/^uploads\/products\/'.preg_quote((string)$this->sellerId,'/').'\/product_[a-f0-9]{40}\.(?:jpg|png|webp)$/D';
        if(!preg_match($pattern,$path))throw new \InvalidArgumentException('Use product images uploaded to this seller account');
        $directory=realpath(UPLOAD_DIR.'/products/'.$this->sellerId);$file=realpath(UPLOAD_DIR.'/'.substr($path,strlen('uploads/')));
        if($directory===false||$file===false||dirname($file)!==$directory||!is_file($file))throw new \InvalidArgumentException('Product image was not found in this seller account');
    }
    private function assertMedia(array $data): void {
        if(!empty($data['thumbnail']))$this->verifyImagePath((string)$data['thumbnail']);
        foreach($data['gallery_images']??[] as $path)$this->verifyImagePath((string)$path);
    }
    private function withMedia(array $data,array $files,?array $current=null): array {
        $upload=$this->uploads->productImages($this->sellerId,$files['thumbnail']??[],$files['gallery']??[]);
        if($upload['thumbnail']!==null)$data['thumbnail']=$upload['thumbnail'];
        elseif($current!==null&&!array_key_exists('thumbnail',$data))$data['thumbnail']=$current['thumbnail']??'';
        if($upload['gallery_images'])$data['gallery_images']=$upload['gallery_images'];
        elseif($current!==null&&!array_key_exists('gallery_images',$data))$data['gallery_images']=$current['gallery_images']??[];
        $thumbnail=$data['thumbnail']??$current['thumbnail']??null;$gallery=$data['gallery_images']??$current['gallery_images']??[];
        if(is_string($gallery)){$gallery=json_decode($gallery,true);if(!is_array($gallery))throw new \InvalidArgumentException('Gallery images must be a valid list');}
        if(!is_array($gallery))throw new \InvalidArgumentException('Gallery images must be a list');
        $data['gallery_images']=array_values($gallery);
        $data['images']=array_map(static fn(string $src):array=>['src'=>$src,'alt'=>'Seller product image'],array_values(array_filter([$thumbnail,...$gallery],static fn($src):bool=>is_string($src)&&$src!=='')));
        return [$data,$upload['absolute_paths']];
    }
    private function checkSku(string $sku,int $storeId,?int $exclude=null): void {
        $sql='SELECT id FROM products WHERE store_id=? AND lower(sku)=lower(?)';$args=[$storeId,$sku];if($exclude!==null){$sql.=' AND id<>?';$args[]=$exclude;}
        $query=$this->db->prepare($sql.' LIMIT 1');$query->execute($args);if($query->fetchColumn()!==false)throw new \InvalidArgumentException('SKU is already used by a product in this store');
    }
    private function draftDefaults(array $data): array {
        $data+=['name'=>'Untitled product','sku'=>'DRAFT-'.strtoupper(bin2hex(random_bytes(5))),'category'=>'','brand'=>'','description'=>'','price'=>0.0,'discount_price'=>null,'stock'=>0,'low_stock_threshold'=>5,'thumbnail'=>null,'gallery_images'=>[],'specifications'=>[],'variants'=>[],'images'=>[],'store_category_id'=>'','status'=>'Draft'];
        if(trim((string)$data['name'])==='')$data['name']='Untitled product';
        if(trim((string)$data['sku'])==='')$data['sku']='DRAFT-'.strtoupper(bin2hex(random_bytes(5)));
        return $data;
    }
    private function validateDiscount(array $data,array $current=[]): void {
        $price=array_key_exists('price',$data)?$data['price']:($current['price']??null);$discount=array_key_exists('discount_price',$data)?$data['discount_price']:($current['discount_price']??null);
        if($discount!==null&&$price!==null&&(float)$discount>=(float)$price)throw new \InvalidArgumentException('Discount price must be below the selling price');
    }
    public function getProducts($id=null,array $data=[]): array {return $this->products->all();}
    public function index($id=null,array $data=[]): array {return $this->getProducts($id,$data);}
    public function getProduct($id=null,array $data=[]): array {return $this->products->find($this->productId($id,$data));}
    public function getById($id=null,array $data=[]): array {return $this->getProduct($id,$data);}
    public function createProduct($id=null,array $data=[]): array {
        $storeId=$this->storeId();[$input,$uploaded]=$this->withMedia($data,$_FILES);
        try{$validated=ProductValidator::validate($input);$this->assertMedia($validated);$this->validateDiscount($validated);$this->checkSku($validated['sku'],$storeId);$validated['status']=$validated['status']??'Draft';$validated['store_category_id']=$validated['store_category_id']??'';$newId=$this->products->create($storeId,$validated);return $this->products->find($newId);}
        catch(\Throwable $error){$this->removeUploads($uploaded);throw $error;}
    }
    public function create($id=null,array $data=[]): array {return $this->createProduct($id,$data);}
    public function saveDraft($id=null,array $data=[]): array {
        $productId=$id??$data['product_id']??$data['id']??null;
        if($productId===null||$productId===''){
            $storeId=$this->storeId();[$input,$uploaded]=$this->withMedia($data,$_FILES);
            try{$validated=$this->draftDefaults(ProductValidator::draft($input));$this->assertMedia($validated);$validated['status']='Draft';$this->checkSku($validated['sku'],$storeId);$newId=$this->products->create($storeId,$validated);return $this->products->find($newId);}
            catch(\Throwable $error){$this->removeUploads($uploaded);throw $error;}
        }
        return $this->updateDraft($this->requiredId($productId),$data);
    }
    private function updateDraft(int $id,array $data): array {
        $current=$this->products->find($id);$storeId=$this->storeId();[$input,$uploaded]=$this->withMedia($data,$_FILES,$current);
        try{$validated=ProductValidator::draft($input);$this->assertMedia($validated);if(array_key_exists('sku',$validated))$this->checkSku($validated['sku'],$storeId,$id);$this->validateDiscount($validated,$current);$validated['status']='Draft';$this->transaction(function()use($id,$validated,$current){$this->products->update($id,$validated);if(array_key_exists('stock',$validated))(new InventoryService($this->db))->setStock($id,$this->storeId(),$validated['stock']);});return $this->products->find($id);}
        catch(\Throwable $error){$this->removeUploads($uploaded);throw $error;}
    }
    public function updateProduct($id=null,array $data=[]): array {
        $productId=$this->productId($id,$data);$current=$this->products->find($productId);$storeId=$this->storeId();[$input,$uploaded]=$this->withMedia($data,$_FILES,$current);
        try{$validated=ProductValidator::validate($input,true);if(!$validated)throw new \InvalidArgumentException('No product fields supplied');$this->assertMedia($validated);if(isset($validated['sku']))$this->checkSku($validated['sku'],$storeId,$productId);$this->validateDiscount($validated,$current);if(($validated['status']??'')==='Published'&&(int)$current['hidden']===1)throw new \RuntimeException('Management has hidden this product',403);if(($validated['status']??'')==='Published'&&(int)$current['archived']===1)throw new \RuntimeException('Restore the archived product before publishing',409);$this->transaction(function()use($productId,$validated){$this->products->update($productId,$validated);if(array_key_exists('stock',$validated))(new InventoryService($this->db))->setStock($productId,$this->storeId(),$validated['stock']);});$updated=$this->products->find($productId);$this->removeReplacedMedia($current,$updated);return $updated;}
        catch(\Throwable $error){$this->removeUploads($uploaded);throw $error;}
    }
    public function update($id=null,array $data=[]): array {return $this->updateProduct($id,$data);}
    public function updateStock($id=null,array $data=[]): array {
        $productId=$this->productId($id,$data);$this->products->find($productId);$raw=$data['stock_quantity']??$data['stock']??$data['quantity']??null;$quantity=filter_var($raw,FILTER_VALIDATE_INT);if($quantity===false||$quantity<0)throw new \InvalidArgumentException('Stock quantity must be a non-negative whole number');(new InventoryService($this->db))->setStock($productId,$this->storeId(),$quantity);return $this->products->find($productId);
    }
    public function publishProduct($id=null,array $data=[]): array {
        $productId=$this->productId($id,$data);$product=$this->products->find($productId);if((int)$product['hidden']===1)throw new \RuntimeException('Management has hidden this product',403);if((int)$product['archived']===1)throw new \RuntimeException('Restore the archived product before publishing',409);$this->products->setStatus($productId,'Published');return $this->products->find($productId);
    }
    public function publish($id=null,array $data=[]): array {return $this->publishProduct($id,$data);}
    public function unpublishProduct($id=null,array $data=[]): array {$productId=$this->productId($id,$data);$this->products->find($productId);$this->products->setStatus($productId,'Draft');return $this->products->find($productId);}
    public function unpublish($id=null,array $data=[]): array {return $this->unpublishProduct($id,$data);}
    public function toggleProduct($id=null,array $data=[]): array {$product=$this->getProduct($id,$data);return $product['status']==='Published'?$this->unpublishProduct($id,$data):$this->publishProduct($id,$data);}
    public function archiveProduct($id=null,array $data=[]): array {$productId=$this->productId($id,$data);$this->products->find($productId);$this->products->setStatus($productId,'Archived');return $this->products->find($productId);}
    public function archive($id=null,array $data=[]): array {return $this->archiveProduct($id,$data);}
    public function restoreProduct($id=null,array $data=[]): array {$productId=$this->productId($id,$data);$this->products->find($productId);$this->products->setStatus($productId,'Draft');return $this->products->find($productId);}
    public function deleteProduct($id=null,array $data=[]): array {
        $productId=$this->productId($id,$data);$product=$this->products->find($productId);
        foreach(['order_items','returns','reviews'] as $table){$query=$this->db->prepare("SELECT 1 FROM {$table} WHERE product_id=? LIMIT 1");$query->execute([$productId]);if($query->fetchColumn()!==false)throw new \RuntimeException('This product has customer history and must be archived instead of deleted',409);}
        if($this->products->delete($productId)!==1)throw new \RuntimeException('Product could not be deleted',409);
        foreach(array_merge([$product['thumbnail']],$product['gallery_images']) as $path)$this->deleteOwnedImage($path);
        return ['product_id'=>$productId,'deleted'=>true];
    }
    public function delete($id=null,array $data=[]): array {return $this->deleteProduct($id,$data);}
    public function uploadImages($id=null,array $data=[]): array {
        $productId=$this->productId($id,$data);$current=$this->products->find($productId);$upload=$this->uploads->productImages($this->sellerId,$_FILES['thumbnail']??[],$_FILES['gallery']??[]);
        try{if($upload['thumbnail']===null&&!$upload['gallery_images'])throw new \InvalidArgumentException('Choose a thumbnail or gallery image to upload');$thumbnail=$upload['thumbnail']??$current['thumbnail'];$gallery=$upload['gallery_images']?:$current['gallery_images'];$images=array_map(static fn(string $src):array=>['src'=>$src,'alt'=>'Seller product image'],array_values(array_filter([$thumbnail,...$gallery],static fn($src):bool=>is_string($src)&&$src!=='')));$this->products->update($productId,['thumbnail'=>$thumbnail,'gallery_images'=>$gallery,'images'=>$images]);$updated=$this->products->find($productId);$this->removeReplacedMedia($current,$updated);return $updated;}
        catch(\Throwable $error){$this->removeUploads($upload['absolute_paths']);throw $error;}
    }
    private function removeReplacedMedia(array $before,array $after): void {$keep=array_merge([$after['thumbnail']??null],$after['gallery_images']??[]);foreach(array_merge([$before['thumbnail']??null],$before['gallery_images']??[]) as $path)if($path&&!in_array($path,$keep,true))$this->deleteOwnedImage((string)$path);}
    private function deleteOwnedImage(?string $path): void {if(!$path||!preg_match('/^uploads\/products\/'.preg_quote((string)$this->sellerId,'/').'\/product_[a-f0-9]{40}\.(?:jpg|png|webp)$/D',$path))return;$directory=realpath(UPLOAD_DIR.'/products/'.$this->sellerId);$file=realpath(UPLOAD_DIR.'/'.substr($path,strlen('uploads/')));if($directory!==false&&$file!==false&&dirname($file)===$directory&&is_file($file))@unlink($file);}
    public function uploadProductImage($id=null,array $data=[]): array {return $this->uploadImages($id,$data);}
    public function uploadImage($id=null,array $data=[]): array {return $this->uploadImages($id,$data);}
}
