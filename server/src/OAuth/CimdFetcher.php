<?php
declare(strict_types=1);

namespace StretchEffect\OAuth;

/** Fetches a Client ID Metadata Document. Injectable so tests never touch the network. */
interface CimdFetcher
{
    /**
     * @return array<string,mixed> decoded JSON document
     * @throws \RuntimeException on any problem (message is safe to log, never shown raw to the user)
     */
    public function fetch(string $url): array;
}
