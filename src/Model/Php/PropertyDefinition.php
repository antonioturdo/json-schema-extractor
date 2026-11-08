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

        /**
         * Who can read the property.
         *
         * Under PHP 8.4 asymmetric visibility, `public private(set)` is read as `Public`.
         *
         * @todo 4.0: make this argument required. Defaulting to `Public` treats a property
         *       as exposed whenever a custom discoverer forgets to pass it.
         */
        private readonly PropertyVisibility $readVisibility = PropertyVisibility::Public,

        /**
         * Whether the property holds a value that can be read.
         *
         * False only for PHP 8.4 virtual properties without a `get` hook.
         */
        private readonly bool $readable = true,
    ) {
        $this->setRequired($required);
    }

    public function getName(): string
    {
        return $this->propertyName;
    }

    public function getReadVisibility(): PropertyVisibility
    {
        return $this->readVisibility;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }
}
