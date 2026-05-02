<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

/**
 * Represents an inline (non class-backed) object shape.
 *
 * This can be used to model shaped arrays, validator-driven collections, or any "virtual DTO" where
 * the object has a known set of fields but no concrete PHP class to recurse into.
 */
final class InlineObjectDefinition implements ObjectShapeDefinitionInterface
{
    use ObjectShapeDefinitionTrait;

    /**
     * @param array<string, FieldDefinitionInterface> $properties
     */
    public function __construct(
        public readonly string $id,
        public array $properties = [],
        public ?string $title = null,
        public ?string $description = null,
        public ?bool $additionalProperties = null,
    ) {}
}
