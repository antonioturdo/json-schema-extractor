<?php

namespace Zeusi\JsonSchemaGenerator\Mapper;

use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\JsonSchema\Schema;

/**
 * Translates our mutated ClassDefinition into the final Schema object representing the JSON Schema.
 */
interface SchemaMapperInterface
{
    /**
     * @param ClassDefinition $definition The enriched class definition
     * @param callable(string): Schema $schemaProvider A callable to request the nested Schema of another class
     */
    public function map(ClassDefinition $definition, callable $schemaProvider): Schema;
}
