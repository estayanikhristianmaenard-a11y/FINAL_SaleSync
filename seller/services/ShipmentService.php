<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class ShipmentService {
    public function __construct(private PDO $db) {}
    public function create(int $orderId,int $storeId): array {
        $q=$this->db->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=? AND store_id=?');$q->execute([$orderId,$storeId]);
        if(!(int)$q->fetchColumn())throw new \RuntimeException('Order not found for this store',404);
        $q=$this->db->prepare('SELECT * FROM shipments WHERE order_id=? AND store_id=?');$q->execute([$orderId,$storeId]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing)return $existing;
        $q=$this->db->prepare('INSERT INTO shipments(order_id,store_id) VALUES(?,?)');$q->execute([$orderId,$storeId]);$shipmentId=(int)$this->db->lastInsertId();
        (new NotificationService($this->db))->create($storeId,'order_received','Order received','New order #'.$orderId.' is ready to process.');
        return $this->get($shipmentId,$storeId);
    }
    public function get(int $shipmentId,int $storeId): array { $q=$this->db->prepare('SELECT sh.*,o.customer_id,o.recipient_name,o.recipient_phone,o.address,o.status order_status,o.paid,o.total FROM shipments sh JOIN orders o ON o.id=sh.order_id WHERE sh.id=? AND sh.store_id=?');$q->execute([$shipmentId,$storeId]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new \RuntimeException('Shipment not found',404);return $r; }
    public function assignTracking(int $shipmentId,int $storeId,string $tracking): array {
        $tracking=ShipmentValidator::tracking($tracking);if($tracking===null)throw new \InvalidArgumentException('Tracking number is required');$s=$this->get($shipmentId,$storeId);
        if(in_array($s['status'],['Cancelled','Completed'],true))throw new \RuntimeException('Tracking cannot be changed for this shipment',409);
        $q=$this->db->prepare('UPDATE shipments SET tracking=? WHERE id=? AND store_id=?');$q->execute([$tracking,$shipmentId,$storeId]);return $this->get($shipmentId,$storeId);
    }
}
