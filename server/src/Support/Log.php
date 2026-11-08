<?php
declare(strict_types=1);

namespace StretchEffect\Support;

final class Log
{
    public static ?string $path = null;

    public static function error(string $message): void
    {
        $path = self::$path ?? (defined('SE_SERVER_DIR') ? SE_SERVER_DIR . '/var/error.log' : null);
        if ($path === null) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($path, '[' . gmdate('c') . '] ' . str_replace(["\r", "\n"], ' ', $message) . "\n", FILE_APPEND | LOCK_EX);
    }
}
