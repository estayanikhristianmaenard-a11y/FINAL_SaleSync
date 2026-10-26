<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'SaleSync\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

$config = require dirname(__DIR__) . '/config/example.php';
$local = dirname(__DIR__) . '/config/local.php';
if (is_file($local)) {
    $config = array_replace($config, require $local);
}
// Environment overrides also allow isolated integration tests.
foreach (['SALESYNC_DB' => 'database', 'SALESYNC_URL' => 'app_url'] as $env => $key) {
    if (getenv($env) !== false) {
        $config[$key] = getenv($env);
    }
}
date_default_timezone_set('UTC');
return $config;
