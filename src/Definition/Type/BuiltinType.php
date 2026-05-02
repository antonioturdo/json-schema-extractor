<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

/**
 * Represents a built-in PHP type name (string, int, float, bool, array, object, mixed, null, ...).
 */
final class BuiltinType implements TypeExpr
{
    public function __construct(
        public string $name,
    ) {}
}
