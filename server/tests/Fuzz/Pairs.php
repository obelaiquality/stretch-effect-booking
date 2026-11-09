<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

/** A JSON object written key by key, so it can hold duplicate keys. */
final class Pairs
{
    /** @param list<array{0:string,1:mixed}> $pairs */
    public function __construct(public readonly array $pairs) {}
}
