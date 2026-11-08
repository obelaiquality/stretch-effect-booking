<?php
declare(strict_types=1);

// Shared CLI bootstrap: refuses to run under a web server, loads config and builds the App.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SE_SERVER_DIR', dirname(__DIR__));
require SE_SERVER_DIR . '/src/bootstrap.php';

use StretchEffect\App;

function se_app(): App
{
    $file = getenv('SE_CONFIG') ?: SE_SERVER_DIR . '/config.php';
    if (!is_file($file)) {
        fwrite(STDERR, "Config not found: $file\nCopy server/config.example.php to server/config.php first.\n");
        exit(1);
    }
    return App::fromFile($file);
}

/** Read a line (optionally without echo). */
function se_ask(string $prompt, bool $hidden = false): string
{
    fwrite(STDOUT, $prompt);
    if ($hidden && function_exists('posix_isatty') && posix_isatty(STDIN)) {
        shell_exec('stty -echo');
        $line = fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    } else {
        $line = fgets(STDIN);
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

/** @return array<string,string> --key=value options */
function se_opts(array $argv): array
{
    $o = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $a, $m)) {
            $o[$m[1]] = $m[2] ?? '1';
        }
    }
    return $o;
}
