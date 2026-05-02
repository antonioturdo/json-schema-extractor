<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

/**
 * Represents an AND between multiple types, e.g. `Foo&Bar`.
 */
final class IntersectionType implements TypeExpr
{
    /**
     * @param list<TypeExpr> $types
     */
    public function __construct(
        public array $types,
    ) {}
}
