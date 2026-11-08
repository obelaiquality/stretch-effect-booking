<?php
declare(strict_types=1);

// Usage (crontab, every 10 minutes): */10 * * * * /usr/bin/php /home/USER/stretch-effect/server/bin/cron.php
// Syncs Google busy blocks into the cache and deletes expired codes, tokens, rate limits and old idempotency keys.
require __DIR__ . '/_boot.php';

$app = se_app();
$result = $app->maintenance()->run();
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
