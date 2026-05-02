<?php

namespace Zeusi\JsonSchemaExtractor\Model\Php;

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
        private readonly string $propertyName,

        /**
         * Whether the property must be present in the serialized output.
         */
        bool $required = false,
    ) {
        $this->setRequired($required);
    }

    public function getName(): string
    {
        return $this->propertyName;
    }
}
