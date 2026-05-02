<?php

namespace Zeusi\JsonSchemaExtractor\Enricher;

use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;

/**
 * Enriches the discovered PHP class model with metadata from external sources.
 *
 * Enrichers mutate the given ClassDefinition in place. Use the runtime helpers
 * when applying common merge/update policies to keep behavior consistent.
 */
interface EnricherInterface
{
    public function enrich(ClassDefinition $definition, ExtractionContext $context, EnrichmentRuntime $runtime): void;
}
