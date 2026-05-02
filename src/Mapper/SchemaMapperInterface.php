<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Schema;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;

/**
 * Translates the serialized object definition into the final Schema object representing the JSON Schema.
 */
interface SchemaMapperInterface
{
    /**
     * @param SerializedObjectDefinition $definition The serialized object definition
     * @param callable(string): Schema $schemaProvider A callable to request the nested Schema of another class
     */
    public function map(SerializedObjectDefinition $definition, callable $schemaProvider): Schema;
}
