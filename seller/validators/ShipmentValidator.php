<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ShipmentValidator {
    public static function tracking(mixed $value): ?string {
        if($value===null)return null;$tracking=trim((string)$value);if($tracking==='')return null;
        if(strlen($tracking)>120)throw new \InvalidArgumentException('Tracking number must be 120 characters or fewer');
        return $tracking;
    }
    public static function status(mixed $value): string {
        $status=trim((string)$value);if(!in_array($status,['To Process','Processing','Ready for Shipment','Shipped','Completed','Cancelled'],true))throw new \InvalidArgumentException('Invalid shipment status');return $status;
    }
}
