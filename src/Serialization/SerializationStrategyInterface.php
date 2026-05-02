<?php

namespace Zeusi\JsonSchemaExtractor\Serialization;

use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;

interface SerializationStrategyInterface
{
    public function project(ClassDefinition $definition, ExtractionContext $context): SerializedObjectDefinition;
}
