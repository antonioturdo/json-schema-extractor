<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class AttributesCompany
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
        public readonly string $taxId,
    ) {}
}
