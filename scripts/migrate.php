<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/app/bootstrap.php';
$db = new SaleSync\Core\Database($config['database']);
$db->run('CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY)');
$backupMade=false;
foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
    $name = basename($file);
    if (!$db->one('SELECT name FROM migrations WHERE name = ?', [$name])) {
        if (!$backupMade && $db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")) {
            $directory=dirname($config['database']).'/backups';
            if (!is_dir($directory)) mkdir($directory,0700,true);
            $backup=$directory.'/before-migration-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
            $db->pdo->exec('VACUUM INTO '.$db->pdo->quote($backup));
            $backupMade=true;
            echo "Saved a private database backup before upgrading.\n";
        }
        $db->migrate(function () use ($db, $file, $name): void {
            $db->pdo->exec(file_get_contents($file));
            $db->run('INSERT INTO migrations(name) VALUES (?)', [$name]);
        });
        echo "Applied $name\n";
    }
}
echo "Database is up to date.\n";
