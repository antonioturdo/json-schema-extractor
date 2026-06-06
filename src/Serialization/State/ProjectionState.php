<?php

namespace Zeusi\JsonSchemaExtractor\Serialization\State;

/**
 * Traversal state that flows down the serialized graph along reference edges.
 *
 * It is authored exclusively by a {@see \Zeusi\JsonSchemaExtractor\Serialization\SerializationStrategyInterface}:
 * the strategy seeds it for the root and narrows/accumulates it for each nested reference
 * (e.g. Symfony Serializer `ATTRIBUTES` slices or `MaxDepth` counters). The extractor only
 * carries it and reads {@see self::viewKey()}; it never inspects the concrete contents.
 *
 * `viewKey()` is the fingerprint that distinguishes serialized views of the same class:
 * two states with the same key denote the same view and are deduplicated to one definition.
 */
interface ProjectionState
{
    public function viewKey(): string;
}
