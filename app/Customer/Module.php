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

    public function __construct(array $config, ?Database $database = null, ?Gateway $gateway = null)
    {
        $this->database = $database ?? new Database($config['database']);
        $this->gateway = $gateway ?? new PayMongoGateway($config);
        $this->auth = new AuthService($this->database, new Mailer($config));
        $this->catalog = new CatalogService($this->database);
        $this->profile = new ProfileService($this->database);
        $this->cart = new CartService($this->database, $config['shipping_fee']);
        $this->orders = new OrderService($this->database, $this->cart);
        $this->payments = new PaymentService($this->database, $this->orders, $this->gateway);
    }
}
