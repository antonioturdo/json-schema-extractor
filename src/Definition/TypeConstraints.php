<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

/**
 * Type-level constraints (validating keywords).
 *
 * Constraints restrict the set of valid values. They are independent from field/presence concerns like
 * required/serializedName, which belong to {@see FieldDefinitionInterface}.
 *
 * Constraints can be attached to any node of a {@see Type\TypeExpr}
 * tree (e.g. a specific union branch, a collection items type, or a map value type) through
 * {@see Type\DecoratedType}.
 */
final class TypeConstraints
{
    /**
     * @param array<mixed> $enum
     */
    public function __construct(
        public array $enum = [],

        // Numeric constraints
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public int|float|null $exclusiveMinimum = null,
        public int|float|null $exclusiveMaximum = null,
        public int|float|null $multipleOf = null,

        // String constraints
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,

        // Array constraints
        public ?int $minItems = null,
        public ?int $maxItems = null,
    ) {}
}
