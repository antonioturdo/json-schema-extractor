<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

/**
 * Represents an inline (non class-backed) field/member inside an object shape.
 *
 * This is typically used for:
 * - shaped arrays (PHPStan array shapes)
 * - Symfony Validator Collection fields
 * - serializer discriminator/type fields
 *
 * It intentionally does NOT extend {@see PropertyDefinition}: there is no "is-a" relationship between a
 * real PHP class property and a virtual/inline field, even if they share similar metadata.
 */
final class InlineFieldDefinition implements FieldDefinitionInterface
{
    use FieldDefinitionTrait;

    public function __construct(
        /**
         * Stable internal name of the field in the object shape.
         */
        public readonly string $fieldName,
        ?string $serializedName = null,
        bool $required = false,
    ) {
        if ($serializedName !== null) {
            $this->setSerializedName($serializedName);
        }

        $this->setRequired($required);
    }

    public function getName(): string
    {
        return $this->fieldName;
    }
}
