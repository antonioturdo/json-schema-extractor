<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * A reference to another class-backed serialized payload.
 *
 * Carries the referenced class identity; the concrete JSON Schema pointer
 * (e.g. "#/components/schemas/...") is formatted by the mapper, not stored here.
 */
final class SerializedReferenceType implements Type
{
    /**
     * @param class-string $className
     */
    public function __construct(
        public readonly string $className,
    ) {}
}
