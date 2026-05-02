<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

/**
 * Represents a native PHP enum type.
 */
final class EnumType implements TypeExpr
{
    /**
     * @param class-string<\UnitEnum> $className
     */
    public function __construct(
        public string $className,
    ) {}
}
