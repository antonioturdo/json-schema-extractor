<?php

namespace Zeusi\JsonSchemaExtractor\Model\Php;

/**
 * Represents an inline (non class-backed) object shape.
 *
 * This can be used to model shaped arrays, validator-driven collections, or any "virtual DTO" where
 * the object has a known set of fields but no concrete PHP class to recurse into.
 */
final class InlineObjectDefinition
{
    /**
     * @param array<string, FieldDefinitionInterface> $properties
     */
    public function __construct(
        private readonly string $id,
        private array $properties = [],
        private ?string $title = null,
        private ?string $description = null,
        private ?bool $additionalProperties = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, FieldDefinitionInterface>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function addProperty(FieldDefinitionInterface $property): void
    {
        $this->properties[$property->getName()] = $property;
    }

    public function getOrCreateProperty(FieldDefinitionInterface $property): FieldDefinitionInterface
    {
        $existingProperty = $this->getProperty($property->getName());
        if ($existingProperty !== null) {
            return $existingProperty;
        }

        $this->addProperty($property);

        return $property;
    }

    public function getProperty(string $propertyName): ?FieldDefinitionInterface
    {
        return $this->properties[$propertyName] ?? null;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getAdditionalProperties(): ?bool
    {
        return $this->additionalProperties;
    }

    public function setAdditionalProperties(?bool $additionalProperties): void
    {
        $this->additionalProperties = $additionalProperties;
    }
}
