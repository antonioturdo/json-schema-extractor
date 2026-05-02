<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Represents a native PHP enum type.
 */
final class EnumType implements Type
{
    /**
     * @param class-string<\UnitEnum> $className
     */
    public function __construct(
        public string $className,
    ) {}
}
