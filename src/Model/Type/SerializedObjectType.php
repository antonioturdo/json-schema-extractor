<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;

/**
 * Represents a serialized/output object shape.
 */
final class SerializedObjectType implements Type
{
    public function __construct(
        public SerializedObjectDefinition $shape,
    ) {}
}
