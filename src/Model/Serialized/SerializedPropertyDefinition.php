<?php

namespace Zeusi\JsonSchemaExtractor\Model\Serialized;

use Zeusi\JsonSchemaExtractor\Model\Type\Type;

/**
 * Represents a property in the serialized/output object shape.
 */
final class SerializedPropertyDefinition
{
    public function __construct(
        /**
         * Final property name in the serialized output.
         */
        public readonly string $name,
        public readonly bool   $required = false,
        public readonly ?Type  $type = null,
    ) {}
}
