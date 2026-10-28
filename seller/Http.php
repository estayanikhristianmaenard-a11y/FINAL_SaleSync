<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Http {
    public static function headers(): void {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        if (session_status() !== PHP_SESSION_ACTIVE) { session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax']); session_start(); }
    }
    public static function json(array $body, int $status=200): void { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
    public static function ok(mixed $data=null, string $message='Operation successful', int $status=200): void { self::json(['success'=>true,'message'=>$message,'data'=>$data],$status); }
    public static function fail(string $message, int $status=400, array $data=[]): void { self::json(['success'=>false,'message'=>$message,'data'=>(object)$data],$status); }
    public static function error(\Throwable $e): void { $code=(int)$e->getCode(); if ($e instanceof \InvalidArgumentException) $code=400; if ($code<400 || $code>599) $code=500; self::fail($code===500?'Internal server error':$e->getMessage(),$code); }
    public static function body(): array { $raw=file_get_contents('php://input'); if (str_contains($_SERVER['CONTENT_TYPE']??'','application/json')) { $v=json_decode($raw?:'{}',true); if (!is_array($v)) throw new \InvalidArgumentException('Invalid JSON body'); return $v; } return $_POST; }
    public static function dispatch(object $controller): void {
        $body=self::body(); $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET'); $action=(string)($_GET['action']??$body['action']??'');
        $map=[
            'products'=>['GET'=>empty($_GET['id'])?'getProducts':'getProduct','POST'=>'createProduct','PATCH'=>'updateProduct','PUT'=>'updateProduct','DELETE'=>'deleteProduct'],
            'orders'=>['GET'=>empty($_GET['id'])?'getOrders':'getOrderDetails','POST'=>'workflow','PATCH'=>'workflow'],
            'shipments'=>['GET'=>'getShipment','POST'=>'createShipment','PATCH'=>'updateShipmentStatus'],
            'refunds'=>['GET'=>empty($_GET['id'])?'getRefunds':'getRefund','POST'=>'createRefund','PATCH'=>'workflow'],
            'returns'=>['GET'=>empty($_GET['id'])?'getReturns':'getReturn','POST'=>'workflow','PATCH'=>'workflow'],
            'inspections'=>['GET'=>'getInspection','POST'=>'createInspection','PATCH'=>'saveNotes'],
            'messages'=>['GET'=>(empty($_GET['conversation_id'])&&empty($_GET['id']))?'getConversations':'getConversation','POST'=>'reply','PATCH'=>'markAsRead'],
            'conversations'=>['GET'=>(empty($_GET['conversation_id'])&&empty($_GET['id']))?'getConversations':'getConversation'],
            'vouchers'=>['GET'=>empty($_GET['id'])?'getVouchers':'getVoucher','POST'=>'createVoucher','PATCH'=>'updateVoucher','PUT'=>'updateVoucher','DELETE'=>'disableVoucher'],
            'flash-deals'=>['GET'=>empty($_GET['id'])?'getRequests':'getRequest','POST'=>'submitRequest','DELETE'=>'cancelRequest'],
            'income'=>['GET'=>'getSummary'],
            'insights'=>['GET'=>'getOverview'],
            'reviews'=>['GET'=>!empty($_GET['product_id'])?'getProductReviews':(empty($_GET['id'])?'getReviews':'getReview'),'POST'=>'replyToReview','PATCH'=>'updateReply','PUT'=>'updateReply'],
            'payouts'=>['GET'=>empty($_GET['id'])?'getPayouts':'getPayout'],
            'dashboard'=>['GET'=>'getDashboard'], 'store'=>['GET'=>'getProfile','PATCH'=>'updateProfile','PUT'=>'updateProfile','POST'=>'uploadLogo'],
            'notifications'=>['GET'=>'getNotifications','PATCH'=>'markRead','POST'=>'markAllRead'],
            'stock'=>['GET'=>'getStock','PATCH'=>'updateStock','POST'=>'requestPOSAllocation'],
        ];
        $route=basename($_SERVER['SCRIPT_NAME']??'','.php'); $methodName=$action?:($map[$route][$method]??'');
        if ($route==='reviews' && $methodName==='seller-reply') $methodName='replyToReview';
        $aliases=[
            'seller-edit'=>'update','seller-voucher'=>'createVoucher','deal-request'=>'submitRequest',
            'store-edit'=>'updateProfile','seller-reply'=>'reply','pos-request'=>'requestAllocation',
            'order-detail'=>'getOrderDetails','seller-cancel'=>'cancelOrder','inspect-return'=>'submitInspection','seller-return'=>'reviewReturn',
            'startPacking'=>'startPacking','markReadyForShipment'=>'markReadyForShipment','recordHandover'=>'recordHandover',
            'completeOrder'=>'completeOrder','updateTracking'=>'updateTracking','assignTrackingNumber'=>'assignTrackingNumber',
            'createShipment'=>'createShipment','updateShipmentStatus'=>'updateShipmentStatus','createRefund'=>'createRefund',
            'approveRefund'=>'approveRefund','rejectRefund'=>'rejectRefund',
            'seller-notification-read'=>'markRead','seller-notifications-read-all'=>'markAllRead',
        ];
        if (isset($aliases[$methodName])) $methodName=$aliases[$methodName];
        if ($methodName==='upload-product-image') $methodName='uploadImage';
        if ($methodName==='toggle-product') $methodName='toggleProduct';
        if ($methodName==='product-archive') $methodName='archiveProduct';
        if ($methodName==='product-restore') $methodName='restoreProduct';
        if ($methodName==='product-publish') $methodName='publishProduct';
        if ($methodName==='product-unpublish') $methodName='unpublishProduct';
        if ($methodName==='stock-adjust') $methodName='adjustStock';
        if ($methodName==='stock-reserve') $methodName='reserveStock';
        if ($methodName==='stock-release') $methodName='releaseReservedStock';
        if ($methodName==='ship-step') $methodName='shipStep';
        if ($methodName==='handover') $methodName='recordHandover';
        if ($methodName==='workflow') {
            $methodName=(string)($body['action']??'');
            if ($route==='refunds') $methodName=$methodName==='approveRefund'?'approveRefund':($methodName==='rejectRefund'?'rejectRefund':$methodName);
        }
        if ($methodName==='ship-step') $methodName='shipStep';
        if ($methodName==='handover') $methodName='recordHandover';
        if ($methodName==='seller-cancel') $methodName='cancelOrder';
        if ($methodName==='order-detail') $methodName='getOrderDetails';
        if ($methodName==='seller-return') $methodName='reviewReturn';
        if ($methodName==='inspect-return') $methodName='submitInspection';
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/',$methodName) || !method_exists($controller,$methodName)) self::fail('Unsupported action',405);
        $result=$controller->$methodName($_GET['id']??$body['id']??null,$body);
        self::ok($result,$route==='dashboard'?'Dashboard loaded':'Operation successful');
    }
}
