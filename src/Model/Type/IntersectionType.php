<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Represents an AND between multiple types, e.g. `Foo&Bar`.
 */
final class IntersectionType implements Type
{
    /**
     * @param list<Type> $types
     */
    public function __construct(
        public array $types,
    ) {}
}
