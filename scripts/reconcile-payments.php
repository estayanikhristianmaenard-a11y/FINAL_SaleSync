<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/app/bootstrap.php';
$module = new SaleSync\Customer\Module($config);
$db = $module->database;
$gateway = $module->gateway;
$payments = $module->payments;
if (!$gateway->ready()) { fwrite(STDERR, "Configure a PayMongo test secret first.\n"); exit(1); }
// Optional cs_ argument recovers a timed-out creation using its provider dashboard reference.
if (isset($argv[1])) {
    if (!preg_match('/^cs_[A-Za-z0-9]+$/', $argv[1])) { fwrite(STDERR, "Expected a Checkout Session ID (cs_...).\n"); exit(1); }
    $payments->apply($gateway->retrieve($argv[1]));
    echo "Reconciled supplied session.\n";
    exit;
}
$failed = false;
foreach ($db->all("SELECT provider_reference FROM payments WHERE provider_reference IS NOT NULL AND status NOT IN ('paid','expired')") as $record) {
    try {
        $payments->apply($gateway->retrieve($record['provider_reference']));
        echo 'Reconciled ' . $record['provider_reference'] . "\n";
    } catch (Throwable $error) {
        $failed = true;
        fwrite(STDERR, $record['provider_reference'] . ': ' . $error->getMessage() . "\n");
    }
}
foreach ($db->all("SELECT o.order_number FROM payments p JOIN orders o ON o.order_id=p.order_id WHERE p.provider_reference IS NULL AND p.status IN ('creating','unknown')") as $record) {
    $failed = true;
    fwrite(STDERR, 'Manual provider lookup required: ' . $record['order_number'] . "\n");
}
exit($failed ? 1 : 0);
