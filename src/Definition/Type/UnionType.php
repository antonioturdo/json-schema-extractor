<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

/**
 * Represents an OR between multiple types, e.g. `Foo|Bar|null`.
 */
final class UnionType implements TypeExpr
{
    /**
     * @param list<TypeExpr> $types
     */
    public function __construct(
        public array $types,
        public ?UnionSemantics $semantics = null,
    ) {}
}
