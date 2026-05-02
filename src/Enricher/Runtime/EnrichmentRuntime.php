<?php

namespace Zeusi\JsonSchemaExtractor\Enricher\Runtime;

final class EnrichmentRuntime
{
    public function __construct(
        public readonly FieldDefinitionUpdater $fieldDefinitionUpdater = new FieldDefinitionUpdater(),
    ) {}
}
