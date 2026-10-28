<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ShipmentController extends Controller {
    public function createShipment($id=null,array $d=[]): array { $orderId=OrderValidator::id($id??$d['order_id']??null);return (new ShipmentService($this->db))->create($orderId,$this->storeId()); }
    public function getShipment($id=null,array $d=[]): array { $shipmentId=$this->requiredId($id??$d['shipment_id']??null);return (new ShipmentService($this->db))->get($shipmentId,$this->storeId()); }
    public function assignTrackingNumber($id=null,array $d=[]): array { $shipmentId=$this->requiredId($id??$d['shipment_id']??null);return (new ShipmentService($this->db))->assignTracking($shipmentId,$this->storeId(),(string)($d['tracking_number']??$d['tracking']??'')); }
    public function updateShipmentStatus($id=null,array $d=[]): array {
        $shipmentId=$this->requiredId($id??$d['shipment_id']??null);$requested=ShipmentValidator::status($d['status']??'');$s=(new ShipmentService($this->db))->get($shipmentId,$this->storeId());
        $action=match([$s['status'],$requested]){['To Process','Processing']=>'startPacking',['Processing','Ready for Shipment']=>'markReady',['Ready for Shipment','Shipped']=>'recordHandover',['Shipped','Completed']=>'completeOrder',default=>throw new \RuntimeException('Invalid shipment status transition',409)};
        return (new OrderWorkflowService($this->db))->move($shipmentId,$this->storeId(),$action,$d);
    }
}
