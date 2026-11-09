<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

use StretchEffect\OAuth\CimdFetcher;

/** Offline stand-in for the Client ID Metadata Document fetcher: serves whatever document the test put there, never touches the network. */
final class FuzzCimd implements CimdFetcher
{
    /** @var array<string,array<string,mixed>> */
    public array $docs = [];

    public function fetch(string $url): array
    {
        if (!isset($this->docs[$url])) {
            throw new \RuntimeException('no such document');
        }
        return $this->docs[$url];
    }
}
