<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

/**
 * Requires the GMP extension to be instantiated: only use it from tests that check for it.
 */
class LedgerEntry
{
    public function __construct(
        public \GMP $amount,
    ) {}
}
