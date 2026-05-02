<?php

namespace Zeusi\JsonSchemaGenerator;

final class SchemaGeneratorOptions
{
    public function __construct(
        public readonly bool $defaultAdditionalProperties = false,
    ) {}
}
