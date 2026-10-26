<?php
declare(strict_types=1);

return [
    'database' => dirname(__DIR__) . '/storage/salesync.sqlite',
    'app_url' => 'http://localhost/FINAL_SaleSync/public',
    'secure_cookies' => false, // Set true when served over HTTPS.
    'session_name' => 'salesync', // All role modules use the same session identity.
    'session_cookie_path' => null, // null uses app_url's path; set to the common app path when mounting modules separately.
    'session_directory' => null, // null uses the database directory's sessions/ folder.
    'mail_driver' => 'log', // log: private development outbox; mail: configured PHP mail transport.
    'mail_from' => 'no-reply@example.com',
    'paymongo_secret' => '', // sk_test_ only. Never put this in frontend code.
    'paymongo_webhook_secret' => '',
    'payment_methods' => ['card', 'gcash'],
    'shipping_fee' => 0, // Integer centavos; confirm the team's shipping rule.
];
