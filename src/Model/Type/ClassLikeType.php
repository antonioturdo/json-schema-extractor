<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Represents a class-like type (class or interface).
 *
 * Enums are also covered by {@see class-string}.
 */
final class ClassLikeType implements Type
{
    /**
     * @param class-string $name
     */
    public function __construct(
        public string $name,
    ) {}
}
