<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedProjection;

/**
 * Translates a resolved serialized projection into the final JSON Schema representation.
 */
interface JsonSchemaMapperInterface
{
    public function map(SerializedProjection $projection): MappingResult;
}
