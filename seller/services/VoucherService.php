<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class VoucherService {
    public function __construct(private PDO $db) {}
    public function all(int $storeId): array { $q=$this->db->prepare('SELECT * FROM vouchers WHERE store_id=? ORDER BY id DESC');$q->execute([$storeId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$r)$r=$this->present($r);unset($r);return $rows; }
    public function get(int $id,int $storeId): array { $q=$this->db->prepare('SELECT * FROM vouchers WHERE id=? AND store_id=?');$q->execute([$id,$storeId]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new \RuntimeException('Voucher not found',404);return $this->present($r); }
    public function create(int $storeId,array $input): array {
        $v=VoucherValidator::validate($input);$this->assertUnique($storeId,$v['code']);
        try{$q=$this->db->prepare('INSERT INTO vouchers(store_id,name,code,scope,discount_type,discount,max_discount,min_spend,usage_limit,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$storeId,$v['name'],$v['code'],$v['scope'],$v['discount_type'],$v['discount'],$v['max_discount'],$v['min_spend'],$v['usage_limit'],$v['starts_at'],$v['ends_at']]);}
        catch(\PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique'))throw new \RuntimeException('Voucher code already exists for this store',409);throw $e;}
        $id=(int)$this->db->lastInsertId();(new NotificationService($this->db))->create($storeId,'voucher_created','Voucher created',$v['name'].' ('.$v['code'].') was created.');return $this->get($id,$storeId);
    }
    public function update(int $id,int $storeId,array $input): array {
        $current=$this->get($id,$storeId);$merged=['name'=>$current['name']?:$current['code'],'code'=>$current['code'],'scope'=>'shop','discount_type'=>$current['discount_type'],'discount'=>$current['discount'],'max_discount'=>$current['max_discount']??$current['discount'],'min_spend'=>$current['min_spend'],'usage_limit'=>$current['usage_limit'],'starts_at'=>$current['starts_at'],'ends_at'=>$current['ends_at']];
        foreach($input as $key=>$value)$merged[$key]=$value;$v=VoucherValidator::validate($merged);$this->assertUnique($storeId,$v['code'],$id);
        if($v['usage_limit']<(int)$current['usage_count'])throw new \RuntimeException('Usage limit cannot be below the recorded usage count',409);
        try{$q=$this->db->prepare('UPDATE vouchers SET name=?,code=?,scope=?,discount_type=?,discount=?,max_discount=?,min_spend=?,usage_limit=?,starts_at=?,ends_at=? WHERE id=? AND store_id=?');$q->execute([$v['name'],$v['code'],$v['scope'],$v['discount_type'],$v['discount'],$v['max_discount'],$v['min_spend'],$v['usage_limit'],$v['starts_at'],$v['ends_at'],$id,$storeId]);}
        catch(\PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique'))throw new \RuntimeException('Voucher code already exists for this store',409);throw $e;}
        (new NotificationService($this->db))->create($storeId,'voucher_updated','Voucher updated',$v['name'].' ('.$v['code'].') was updated.');return $this->get($id,$storeId);
    }
    public function disable(int $id,int $storeId): array { $v=$this->get($id,$storeId);$q=$this->db->prepare('UPDATE vouchers SET enabled=0 WHERE id=? AND store_id=?');$q->execute([$id,$storeId]);(new NotificationService($this->db))->create($storeId,'voucher_disabled','Voucher disabled',$v['code'].' was disabled.');return $this->get($id,$storeId); }
    public function enable(int $id,int $storeId): array { $v=$this->get($id,$storeId);if(strtotime((string)$v['ends_at'])<time())throw new \RuntimeException('Expired vouchers cannot be enabled',409);if((int)$v['usage_count']>=(int)$v['usage_limit'])throw new \RuntimeException('Voucher usage limit has been reached',409);$q=$this->db->prepare('UPDATE vouchers SET enabled=1 WHERE id=? AND store_id=?');$q->execute([$id,$storeId]);return $this->get($id,$storeId); }
    public function delete(int $id,int $storeId): array { $v=$this->get($id,$storeId);if((int)$v['usage_count']>0)throw new \RuntimeException('A voucher with recorded use cannot be deleted',409);$q=$this->db->prepare('DELETE FROM vouchers WHERE id=? AND store_id=?');$q->execute([$id,$storeId]);return ['deleted'=>true,'voucher_id'=>$id]; }
    public function usage(int $id,int $storeId): array { $v=$this->get($id,$storeId);return ['voucher_id'=>$id,'usage_count'=>(int)$v['usage_count'],'usage_limit'=>(int)$v['usage_limit'],'remaining'=>max(0,(int)$v['usage_limit']-(int)$v['usage_count'])]; }
    public function recordUsage(int $id,int $storeId): void { $q=$this->db->prepare('UPDATE vouchers SET usage_count=usage_count+1 WHERE id=? AND store_id=? AND enabled=1 AND (starts_at IS NULL OR starts_at<=CURRENT_TIMESTAMP) AND (ends_at IS NULL OR ends_at>=CURRENT_TIMESTAMP) AND usage_count<usage_limit');$q->execute([$id,$storeId]);if($q->rowCount()!==1)throw new \RuntimeException('Voucher is inactive, expired, or fully redeemed',409); }
    private function assertUnique(int $storeId,string $code,?int $except=null): void { $sql='SELECT id FROM vouchers WHERE store_id=? AND UPPER(code)=UPPER(?)';$args=[$storeId,$code];if($except!==null){$sql.=' AND id<>?';$args[]=$except;}$q=$this->db->prepare($sql.' LIMIT 1');$q->execute($args);if($q->fetchColumn())throw new \RuntimeException('Voucher code already exists for this store',409); }
    private function present(array $v): array { $now=time();$end=strtotime((string)($v['ends_at']??''));$start=strtotime((string)($v['starts_at']??''));$expired=$end!==false&&$end<$now;$active=(int)$v['enabled']===1&&!$expired&&($start===false||$start<=$now)&&(int)$v['usage_count']<(int)($v['usage_limit']??PHP_INT_MAX);$v['status']=$expired?'Expired':($active?'Active':'Inactive');$v['active']=$active;$v['value']=(float)$v['discount'];$v['kind']=$v['discount_type'];$v['min']=(float)$v['min_spend'];$v['max']=(float)($v['max_discount']??$v['discount']);$v['usageLimit']=(int)($v['usage_limit']??100);$v['expires']=substr((string)($v['ends_at']??''),0,10);return $v; }
}
