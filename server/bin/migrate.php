<?php
declare(strict_types=1);

// Usage: php bin/migrate.php   (applies server/migrations/<driver>/*.sql in order; safe to re-run)
require __DIR__ . '/_boot.php';

use StretchEffect\Support\Time;

$app = se_app();
$db = $app->db();
$driver = $db->driver();
$dir = SE_SERVER_DIR . '/migrations/' . $driver;
$files = glob($dir . '/*.sql') ?: [];
sort($files);

$db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)');
$done = array_column($db->all('SELECT filename FROM schema_migrations'), 'filename');

$applied = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    echo "Applying $name ... ";
    // Strip -- comments, split on ; at end of statement.
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (preg_split('/;\s*(?:\r?\n|$)/', (string) $sql) ?: [] as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $db->pdo()->exec($stmt);
        }
    }
    $db->run('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)', [$name, Time::toDb($db->now())]);
    echo "done\n";
    $applied++;
}
echo $applied === 0 ? "Database is up to date ($driver).\n" : "Applied $applied migration(s) ($driver).\n";
