<?php
declare(strict_types=1);

// Loaded by Infection's own process (infection.json5 "bootstrap"): the contracts hold several types per file, so Composer's PSR-4 map cannot find them.
foreach (['DomainError', 'Http', 'RouteHandler', 'Services'] as $f) {
    require_once __DIR__ . '/../src/Contracts/' . $f . '.php';
}
