<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

/**
 * Represents a class-like type (class or interface).
 *
 * Enums are also covered by {@see class-string}.
 */
final class ClassLikeType implements TypeExpr
{
    /**
     * @param class-string|interface-string $name
     */
    public function __construct(
        public string $name,
    ) {}
}
