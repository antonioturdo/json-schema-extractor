<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

use Zeusi\JsonSchemaExtractor\Model\JsonSchema\JsonSchemaInterface;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;

/**
 * Translates the serialized payload definition into the final JSON Schema representation.
 */
interface JsonSchemaMapperInterface
{
    /**
     * @param SerializedPayloadDefinition $definition The serialized payload definition
     * @param callable(string): JsonSchemaInterface $schemaProvider A callable to request the nested JSON Schema of another class
     */
    public function map(SerializedPayloadDefinition $definition, callable $schemaProvider): JsonSchemaInterface;
}
