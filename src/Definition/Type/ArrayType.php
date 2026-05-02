<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

/**
 * Represents a list-like collection type, e.g. `array<Foo>` or `list<int>`.
 */
final class ArrayType implements TypeExpr
{
    public function __construct(
        public TypeExpr $type,
    ) {}
}
