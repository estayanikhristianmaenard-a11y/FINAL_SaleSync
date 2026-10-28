<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class OrderController extends Controller {
    public function getOrders($id=null,array $d=[]): array {
        return $this->rows('SELECT o.*,sh.id shipment_id,sh.store_id shipment_store_id,sh.status shipment_status,sh.tracking,sh.handed_over_at FROM orders o JOIN shipments sh ON sh.order_id=o.id WHERE sh.store_id=? ORDER BY o.id DESC',[$this->storeId()]);
    }
    public function getOrderDetails($id=null,array $d=[]): array {
        $id=OrderValidator::id($id??$d['order_id']??null);$store=$this->storeId();
        $o=$this->one('SELECT o.*,sh.id shipment_id,sh.store_id shipment_store_id,sh.status shipment_status,sh.tracking,sh.handed_over_at FROM orders o JOIN shipments sh ON sh.order_id=o.id WHERE o.id=? AND sh.store_id=?',[$id,$store]);
        if(!$o)throw new \RuntimeException('Order not found',404);
        $o['items']=$this->rows('SELECT oi.product_id,oi.quantity,oi.unit_price,p.name,p.sku FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? AND oi.store_id=? ORDER BY oi.id',[$id,$store]);
        return $o;
    }
    public function startPacking($id=null,array $d=[]): array { return $this->move($id,'startPacking',$d); }
    public function markReadyForShipment($id=null,array $d=[]): array { return $this->move($id,'markReady',$d); }
    public function markReady($id=null,array $d=[]): array { return $this->markReadyForShipment($id,$d); }
    public function recordHandover($id=null,array $d=[]): array { return $this->move($id,'recordHandover',$d); }
    public function completeOrder($id=null,array $d=[]): array { return $this->move($id,'completeOrder',$d); }
    public function shipStep($id=null,array $d=[]): array {
        $sid=$this->requiredId($id??$d['shipment_id']??null);$s=$this->one('SELECT status FROM shipments WHERE id=? AND store_id=?',[$sid,$this->storeId()]);
        if(!$s)throw new \RuntimeException('Shipment not found',404);
        return match($s['status']){'To Process'=>$this->startPacking($sid,$d),'Processing'=>$this->markReadyForShipment($sid,$d),'Ready for Shipment'=>$this->recordHandover($sid,$d),default=>throw new \RuntimeException('Shipment has no seller action available',409)};
    }
    public function updateTracking($id=null,array $d=[]): array {
        $sid=$this->requiredId($id??$d['shipment_id']??null);$tracking=ShipmentValidator::tracking($d['tracking']??$d['tracking_number']??null);
        if($tracking===null)throw new \InvalidArgumentException('Tracking number is required');
        $s=$this->one('SELECT * FROM shipments WHERE id=? AND store_id=?',[$sid,$this->storeId()]);if(!$s)throw new \RuntimeException('Shipment not found',404);
        if(in_array($s['status'],['Cancelled','Completed'],true))throw new \RuntimeException('Tracking cannot be changed for this shipment',409);
        $this->run('UPDATE shipments SET tracking=? WHERE id=? AND store_id=?',[$tracking,$sid,$this->storeId()]);return ['shipment_id'=>$sid,'tracking'=>$tracking];
    }
    private function move($id,string $action,array $d): array { $sid=$this->requiredId($id??$d['shipment_id']??null);return (new OrderWorkflowService($this->db))->move($sid,$this->storeId(),$action,$d); }
    public function cancelOrder($id=null,array $d=[]): array {
        $id=OrderValidator::id($id??$d['order_id']??null);$o=$this->getOrderDetails($id);$store=$this->storeId();
        return $this->transaction(function()use($id,$o,$store){
            $shipments=$this->rows('SELECT * FROM shipments WHERE order_id=? AND store_id=? ORDER BY id',[$id,$store]);
            if(!$shipments||array_filter($shipments,fn($s)=>!in_array($s['status'],['To Process','Processing','Ready for Shipment'],true)))throw new \RuntimeException('Order can no longer be cancelled',409);
            foreach($o['items'] as $item)$this->run('UPDATE products SET stock=stock+? WHERE id=? AND store_id=?',[(int)$item['quantity'],(int)$item['product_id'],$store]);
            $this->run("UPDATE shipments SET status='Cancelled' WHERE order_id=? AND store_id=?",[$id,$store]);
            $remaining=(int)($this->one("SELECT COUNT(*) n FROM shipments WHERE order_id=? AND status<>'Cancelled'",[$id])['n']??0);
            if($remaining===0)$this->run("UPDATE orders SET status='Cancelled' WHERE id=?",[$id]);
            $refund=(new RefundService($this->db))->createForOrder($id,$store,'Seller cancelled order');
            (new NotificationService($this->db))->create($store,'order_cancelled','Order cancelled','Order #'.$id.' was cancelled; refund '.$refund['status'].'.');
            return ['cancelled'=>true,'order_id'=>$id,'refund'=>$refund];
        });
    }
    public function workflow($id=null,array $d=[]): array {
        return match((string)($d['action']??'')){
            'startPacking'=>$this->startPacking($id,$d),'markReady','markReadyForShipment'=>$this->markReadyForShipment($id,$d),
            'recordHandover'=>$this->recordHandover($id,$d),'completeOrder'=>$this->completeOrder($id,$d),
            'cancelOrder'=>$this->cancelOrder($id,$d),'updateTracking'=>$this->updateTracking($id,$d),
            default=>throw new \InvalidArgumentException('Unknown order action')
        };
    }
}
