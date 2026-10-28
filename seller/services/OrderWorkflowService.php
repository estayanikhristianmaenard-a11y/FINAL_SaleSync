<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class OrderWorkflowService {
    public function __construct(private PDO $db) {}
    public function move(int $shipment,int $store,string $action,array $d=[]): array {
        $q=$this->db->prepare('SELECT sh.*,o.id order_id FROM shipments sh JOIN orders o ON o.id=sh.order_id WHERE sh.id=? AND sh.store_id=?');$q->execute([$shipment,$store]);$s=$q->fetch();
        if(!$s)throw new \RuntimeException('Shipment not found',404);
        $next=['startPacking'=>['To Process','Processing','Order processing'],'markReady'=>['Processing','Ready for Shipment','Order ready for shipment'],'recordHandover'=>['Ready for Shipment','Shipped','Order shipped'],'completeOrder'=>['Shipped','Completed','Order completed']];
        if(!isset($next[$action])||$s['status']!==$next[$action][0])throw new \RuntimeException('Invalid order status transition',409);
        $tracking=isset($d['tracking'])?ShipmentValidator::tracking($d['tracking']):null;
        $this->db->beginTransaction();
        try {
            $u=$this->db->prepare('UPDATE shipments SET status=?,tracking=COALESCE(?,tracking),handed_over_at=? WHERE id=? AND store_id=? AND status=?');
            $u->execute([$next[$action][1],$tracking,$action==='recordHandover'?date('c'):null,$shipment,$store,$next[$action][0]]);
            if($u->rowCount()!==1)throw new \RuntimeException('Shipment status changed; reload and retry',409);
            $this->syncOrderStatus((int)$s['order_id']);
            (new NotificationService($this->db))->create($store,'order_status',$next[$action][2],'Order #'.$s['order_id'].' is '.$next[$action][1].'.');
            $this->db->commit();
            return ['shipment_id'=>$shipment,'order_id'=>(int)$s['order_id'],'status'=>$next[$action][1],'tracking'=>$tracking??$s['tracking']];
        } catch(\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
    }
    private function syncOrderStatus(int $order): void {
        $q=$this->db->prepare('SELECT status FROM shipments WHERE order_id=?');$q->execute([$order]);$statuses=$q->fetchAll(PDO::FETCH_COLUMN);
        if(!$statuses)return;
        $active=array_values(array_filter($statuses,fn($v)=>$v!=='Cancelled'));
        if(!$active)$status='Cancelled';
        elseif(count(array_unique($active))===1)$status=$active[0];
        elseif(in_array('Processing',$active,true))$status='Processing';
        elseif(in_array('To Process',$active,true))$status='To Process';
        elseif(in_array('Ready for Shipment',$active,true))$status='Ready for Shipment';
        else $status='Shipped';
        $u=$this->db->prepare('UPDATE orders SET status=? WHERE id=?');$u->execute([$status,$order]);
    }
}
