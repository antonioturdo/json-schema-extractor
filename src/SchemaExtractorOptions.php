<?php

namespace Zeusi\JsonSchemaExtractor;

final class SchemaExtractorOptions
{
    public function __construct(
        public readonly bool $defaultAdditionalProperties = false,
    ) {}
}
