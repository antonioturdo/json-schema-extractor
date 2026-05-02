<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

/**
 * Represents the base definition of a property discovered from a class.
 * This DTO is meant to be mutated by Enrichers along the pipeline.
 */
class PropertyDefinition implements FieldDefinitionInterface
{
    use FieldDefinitionTrait;

    public function __construct(
        /**
         * The original property name in PHP (stable internal identifier).
         */
        public readonly string $propertyName,

        /**
         * The property name as it will appear in the final schema.
         *
         * This is typically affected by serialization layers (e.g. Symfony Serializer SerializedName, name converters).
         */
        ?string $serializedName = null,

        /**
         * Whether the property must be present in the serialized output.
         */
        bool $required = false,
    ) {
        if ($serializedName !== null) {
            $this->setSerializedName($serializedName);
        }

        $this->setRequired($required);
    }

    public function getName(): string
    {
        return $this->propertyName;
    }
}
