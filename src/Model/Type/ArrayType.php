<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Represents a list-like collection type, e.g. `array<Foo>` or `list<int>`.
 */
final class ArrayType implements Type
{
    public function __construct(
        public Type $type,
    ) {}
}
