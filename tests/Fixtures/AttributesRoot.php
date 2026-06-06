<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class AttributesRoot
{
    public function __construct(
        public readonly string $id,
        public readonly AttributesCompany $company,
        public readonly string $note,
    ) {}
}
