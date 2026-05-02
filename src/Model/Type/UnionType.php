<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Represents an OR between multiple types, e.g. `Foo|Bar|null`.
 */
final class UnionType implements Type
{
    /**
     * @param list<Type> $types
     */
    public function __construct(
        public array $types,
        public ?UnionSemantics $semantics = null,
    ) {}
}
