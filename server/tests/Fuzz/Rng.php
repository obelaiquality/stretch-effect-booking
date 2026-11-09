<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

use Random\Engine\Mt19937;
use Random\Randomizer;

/** Seeded random source (Mt19937: same seed gives the same sequence on every PHP version and platform). */
final class Rng
{
    private Randomizer $r;

    public function __construct(public readonly int $seed)
    {
        $this->r = new Randomizer(new Mt19937($seed));
    }

    public function int(int $min, int $max): int
    {
        return $this->r->getInt($min, $max);
    }

    public function chance(float $p): bool
    {
        return $this->r->getInt(0, 999999) < (int) ($p * 1000000);
    }

    /** @template T @param non-empty-list<T> $items @return T */
    public function pick(array $items): mixed
    {
        return $items[$this->r->getInt(0, count($items) - 1)];
    }

    public function bytes(int $n): string
    {
        return $n <= 0 ? '' : $this->r->getBytes($n);
    }

    /** @param array<string,int|float> $weights name => weight. @return string chosen name */
    public function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $x = $this->r->getInt(0, 999999) / 1000000 * $total;
        foreach ($weights as $name => $w) {
            $x -= $w;
            if ($x < 0) {
                return (string) $name;
            }
        }
        return (string) array_key_last($weights);
    }

    /** Random lowercase hex of $n bytes (2n chars). */
    public function hex(int $n): string
    {
        return bin2hex($this->bytes($n));
    }
}
