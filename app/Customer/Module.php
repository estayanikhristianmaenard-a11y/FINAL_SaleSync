<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Auth\{AuthService, Mailer};
use SaleSync\Core\Database;
use SaleSync\Payments\{Gateway, PayMongoGateway, PaymentService};

/** Composes customer services around the host application's shared dependencies. */
final class Module
{
    public readonly Database $database;
    public readonly AuthService $auth;
    public readonly CatalogService $catalog;
    public readonly ProfileService $profile;
    public readonly CartService $cart;
    public readonly OrderService $orders;
    public readonly Gateway $gateway;
    public readonly PaymentService $payments;
    public readonly NotificationService $notifications;
    public readonly SavedProductService $saved;
    public readonly VoucherService $vouchers;
    public readonly MessageService $messages;
    public readonly UploadService $uploads;
    public readonly ReturnService $returns;
    public readonly SellerApplicationService $applications;

    public function __construct(array $config, ?Database $database = null, ?Gateway $gateway = null)
    {
        $this->database = $database ?? new Database($config['database']);
        $this->gateway = $gateway ?? new PayMongoGateway($config);
        $this->auth = new AuthService($this->database, new Mailer($config), $config['minimum_customer_age'] ?? 18);
        $this->catalog = new CatalogService($this->database);
        $this->profile = new ProfileService($this->database);
        $this->cart = new CartService($this->database, $config['shipping_fee']);
        $this->orders = new OrderService($this->database, $this->cart);
        $this->payments = new PaymentService($this->database, $this->orders, $this->gateway);
        $this->notifications = new NotificationService($this->database);
        $this->saved = new SavedProductService($this->database);
        $this->vouchers = new VoucherService($this->database);
        $this->messages = new MessageService($this->database);
        $this->uploads = new UploadService($this->database,$config);
        $this->returns = new ReturnService($this->database,$this->uploads,$config['return_request_days']??7);
        $this->applications = new SellerApplicationService($this->database,$this->uploads);
    }
}
