<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = new SaleSync\Core\Database($config['database']);
$db->run('CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY)');
foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
    $name = basename($file);
    if (!$db->one('SELECT name FROM migrations WHERE name = ?', [$name])) {
        $db->transaction(function () use ($db, $file, $name): void {
            $db->pdo->exec(file_get_contents($file));
            $db->run('INSERT INTO migrations(name) VALUES (?)', [$name]);
        });
        echo "Applied $name\n";
    }
}
echo "Database is up to date.\n";
