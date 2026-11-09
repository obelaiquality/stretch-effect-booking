<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

/** A token written into a JSON body verbatim (numbers like 1e999, NaN, bare words, or deliberately broken syntax). */
final class RawJson
{
    public function __construct(public readonly string $text) {}
}
