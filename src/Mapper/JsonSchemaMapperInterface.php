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
     * @param callable(string): SerializedPayloadDefinition $payloadProvider A callable to request the nested serialized payload of another class
     */
    public function map(SerializedPayloadDefinition $definition, callable $payloadProvider): JsonSchemaInterface;
}
