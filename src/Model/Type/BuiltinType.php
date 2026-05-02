<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Represents a built-in PHP type name (string, int, float, bool, array, object, mixed, null, ...).
 */
final class BuiltinType implements Type
{
    public function __construct(
        public string $name,
    ) {}
}
