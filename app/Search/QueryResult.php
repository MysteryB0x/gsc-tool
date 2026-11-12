<?php

namespace App\Search;

final class QueryResult
{
    /** @param  list<array<string, mixed>>  $rows */
    public function __construct(
        public array $rows,
        public int $credits,
        public int $bytesBilled,
        public bool $fromCache,
        public int $queryId,
    ) {}
}
