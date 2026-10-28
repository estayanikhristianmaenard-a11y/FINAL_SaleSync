<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class StockController extends Controller {
    public function getStock($id=null,array $d=[]): array {
        return $this->rows('SELECT id product_id,name,sku,stock total_stock,reserved,reserved reserved_stock,stock-reserved available_stock,pos_ref pos_reference FROM products WHERE store_id=? AND archived=0 ORDER BY name',[$this->storeId()]);
    }
    public function updateStock($id=null,array $d=[]): array {
        $id=$this->requiredId($id??$d['product_id']??null);$p=$this->owned('products',$id);$qty=StockValidator::quantity($d['stock']??null,'Stock',true);
        if($qty<(int)$p['reserved'])throw new \InvalidArgumentException('Stock cannot be lower than reserved stock');
        $this->run('UPDATE products SET stock=? WHERE id=? AND store_id=?',[$qty,$id,$this->storeId()]);return $this->stockFor($id);
    }
    public function adjustStock($id=null,array $d=[]): array {
        $id=$this->requiredId($id??$d['product_id']??null);$p=$this->owned('products',$id);
        $delta=filter_var($d['adjustment']??$d['delta']??null,FILTER_VALIDATE_INT);if($delta===false||$delta===0)throw new \InvalidArgumentException('A non-zero integer adjustment is required');
        $next=(int)$p['stock']+$delta;if($next<(int)$p['reserved'])throw new \InvalidArgumentException('Adjustment would reduce stock below reserved units');
        $this->run('UPDATE products SET stock=? WHERE id=? AND store_id=?',[$next,$id,$this->storeId()]);return $this->stockFor($id);
    }
    public function reserveStock($id=null,array $d=[]): array {
        $id=$this->requiredId($id??$d['product_id']??null);$qty=StockValidator::quantity($d['quantity']??null,'Quantity');$s=$this->storeId();
        return $this->transaction(function()use($id,$qty,$s){$p=$this->owned('products',$id);if((int)$p['stock']-(int)$p['reserved']<$qty)throw new \RuntimeException('Insufficient available stock',409);$this->run('UPDATE products SET reserved=reserved+? WHERE id=? AND store_id=?',[$qty,$id,$s]);return $this->stockFor($id);});
    }
    public function releaseReservedStock($id=null,array $d=[]): array {
        $id=$this->requiredId($id??$d['product_id']??null);$qty=StockValidator::quantity($d['quantity']??null,'Quantity');$s=$this->storeId();
        return $this->transaction(function()use($id,$qty,$s){$p=$this->owned('products',$id);if((int)$p['reserved']<$qty)throw new \RuntimeException('Cannot release more than reserved stock',409);$this->run('UPDATE products SET reserved=reserved-? WHERE id=? AND store_id=?',[$qty,$id,$s]);return $this->stockFor($id);});
    }
    public function requestPOSAllocation($id=null,array $d=[]): array {
        $id=$this->requiredId($id??$d['product_id']??null);$this->owned('products',$id);$qty=StockValidator::quantity($d['quantity']??$d['qty']??null,'Quantity');
        $this->run('INSERT INTO stock_allocations(store_id,product_id,quantity,note) VALUES(?,?,?,?)',[$this->storeId(),$id,$qty,trim((string)($d['note']??$d['reason']??''))?:null]);
        return ['request_id'=>(int)$this->db->lastInsertId(),'product_id'=>$id,'quantity'=>$qty,'status'=>'Pending'];
    }
    public function requestAllocation($id=null,array $d=[]): array { return $this->requestPOSAllocation($id,$d); }
    private function stockFor(int $id): array { $r=$this->one('SELECT id product_id,name,sku,stock total_stock,reserved,reserved reserved_stock,stock-reserved available_stock,pos_ref pos_reference FROM products WHERE id=? AND store_id=?',[$id,$this->storeId()]);if(!$r)throw new \RuntimeException('Product not found',404);return $r; }
}
