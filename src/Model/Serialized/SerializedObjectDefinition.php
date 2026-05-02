<?php

namespace Zeusi\JsonSchemaExtractor\Model\Serialized;

/**
 * Represents an object shape after serialization projection.
 *
 * Named objects can be reused by mappers as schema definitions/references.
 * Anonymous objects are meant to be emitted inline.
 */
final class SerializedObjectDefinition
{
    /**
     * @param array<string, SerializedPropertyDefinition> $properties
     * @param list<class-string> $concreteClasses
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly array $properties = [],
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?bool $additionalProperties = null,
        public readonly array $concreteClasses = [],
    ) {}
}
