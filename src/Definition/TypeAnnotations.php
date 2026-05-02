<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

/**
 * Type-level annotations (non-validating metadata).
 *
 * These fields are meant to mirror JSON Schema "annotation keywords" such as `title` and `description`.
 * Unlike {@see TypeConstraints}, annotations do not restrict the set of valid values; they provide
 * documentation and hints for consumers.
 *
 * Annotations can be attached to any node of a {@see Type\TypeExpr}
 * tree (e.g. a specific union branch, a collection items type, or an inline object shape) through
 * {@see Type\DecoratedType}.
 */
final class TypeAnnotations
{
    /**
     * @param list<mixed> $examples
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $format = null,
        public bool $deprecated = false,
        public array $examples = [],
        public mixed $default = null
    ) {}
}
