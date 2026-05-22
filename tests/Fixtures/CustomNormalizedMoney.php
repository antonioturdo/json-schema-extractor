<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class CustomNormalizedMoney
{
    public function __construct(
        public readonly int $cents,
        public readonly string $currency,
    ) {}
}
