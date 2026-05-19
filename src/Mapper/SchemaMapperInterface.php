<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Schema;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;

/**
 * Translates the serialized payload definition into the final Schema object representing the JSON Schema.
 */
interface SchemaMapperInterface
{
    /**
     * @param SerializedPayloadDefinition $definition The serialized payload definition
     * @param callable(string): Schema $schemaProvider A callable to request the nested Schema of another class
     */
    public function map(SerializedPayloadDefinition $definition, callable $schemaProvider): Schema;
}
