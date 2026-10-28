<?php
declare(strict_types=1);

use SaleSync\Seller\Database;
use SaleSync\Seller\Http;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/SellerMiddleware.php';
require_once __DIR__ . '/../middleware/PermissionMiddleware.php';
require_once __DIR__ . '/../models/BaseModel.php';
require_once __DIR__ . '/../validators/ProductValidator.php';
require_once __DIR__ . '/../validators/StockValidator.php';
require_once __DIR__ . '/../validators/OrderValidator.php';
require_once __DIR__ . '/../validators/ShipmentValidator.php';
require_once __DIR__ . '/../validators/StoreValidator.php';
require_once __DIR__ . '/../validators/VoucherValidator.php';
require_once __DIR__ . '/../validators/MessageValidator.php';
require_once __DIR__ . '/../validators/ReturnValidator.php';
require_once __DIR__ . '/../validators/InspectionValidator.php';
require_once __DIR__ . '/../validators/FlashDealValidator.php';
require_once __DIR__ . '/../validators/FinanceValidator.php';
require_once __DIR__ . '/../validators/PayoutValidator.php';
require_once __DIR__ . '/../validators/InsightValidator.php';
require_once __DIR__ . '/../validators/ReviewReplyValidator.php';
require_once __DIR__ . '/../validators/DashboardValidator.php';
foreach (glob(__DIR__ . '/../models/*.php') ?: [] as $file) require_once $file;
foreach (glob(__DIR__ . '/../services/*.php') ?: [] as $file) require_once $file;
foreach (glob(__DIR__ . '/../controllers/*.php') ?: [] as $file) require_once $file;
require_once __DIR__ . '/../Http.php';

Http::headers();
try {
    $pdo = Database::connection();
    $sellerId = (new SaleSync\Seller\AuthMiddleware())->requireSeller($pdo);
    $controllerMap = [
        'products'=>SaleSync\Seller\ProductController::class, 'orders'=>SaleSync\Seller\OrderController::class,
        'returns'=>SaleSync\Seller\ReturnController::class, 'inspections'=>SaleSync\Seller\InspectionController::class, 'messages'=>SaleSync\Seller\MessageController::class,
        'vouchers'=>SaleSync\Seller\VoucherController::class, 'flash-deals'=>SaleSync\Seller\FlashDealController::class,
        'income'=>SaleSync\Seller\IncomeController::class, 'payouts'=>SaleSync\Seller\PayoutController::class, 'dashboard'=>SaleSync\Seller\DashboardController::class,
        'store'=>SaleSync\Seller\StoreController::class, 'notifications'=>SaleSync\Seller\NotificationController::class,
        'stock'=>SaleSync\Seller\StockController::class,
        'shipments'=>SaleSync\Seller\ShipmentController::class, 'refunds'=>SaleSync\Seller\RefundController::class,
        'insights'=>SaleSync\Seller\InsightsController::class,
        'reviews'=>SaleSync\Seller\ReviewController::class,
        'conversations'=>SaleSync\Seller\ConversationController::class,
    ];
    $route = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    $class = $controllerMap[$route] ?? null;
    if (!$class) throw new RuntimeException('Unknown API route', 404);
    $controller = new $class($pdo, $sellerId);
    Http::dispatch($controller);
} catch (Throwable $e) {
    Http::error($e);
}
