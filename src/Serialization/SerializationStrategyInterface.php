<?php

namespace Zeusi\JsonSchemaExtractor\Serialization;

use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Serialization\State\ProjectionState;

interface SerializationStrategyInterface
{
    /**
     * Seeds the traversal state for the root class, derived from the (frozen) extraction context.
     */
    public function initialState(ExtractionContext $context): ProjectionState;

    /**
     * Projects a single class into its serialized payload under the given traversal state.
     *
     * The strategy does not recurse into nested classes: it emits references for them and
     * lets the extractor resolve them.
     */
    public function project(ClassDefinition $definition, ExtractionContext $context, ProjectionState $state): SerializedPayloadDefinition;
}
