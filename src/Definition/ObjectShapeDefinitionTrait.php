<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

/**
 * @internal
 */
trait ObjectShapeDefinitionTrait
{
    /**
     * Returns the defined properties, keyed by their stable/internal name.
     *
     * @return array<string, FieldDefinitionInterface>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Adds or replaces a property in the object shape.
     */
    public function addProperty(FieldDefinitionInterface $property): void
    {
        $this->properties[$property->getName()] = $property;
    }

    /**
     * Removes a property by its stable/internal name.
     *
     * No-op if the property is not defined.
     */
    public function removeProperty(string $propertyName): void
    {
        unset($this->properties[$propertyName]);
    }

    /**
     * Gets a property by its stable/internal name.
     */
    public function getProperty(string $propertyName): ?FieldDefinitionInterface
    {
        return $this->properties[$propertyName] ?? null;
    }

    /**
     * Returns the object title, if available.
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * Sets the object title.
     */
    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    /**
     * Returns the object description, if available.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Sets the object description.
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /**
     * Returns the `additionalProperties` setting for this object shape.
     */
    public function getAdditionalProperties(): ?bool
    {
        return $this->additionalProperties;
    }

    /**
     * Sets the `additionalProperties` setting for this object shape.
     */
    public function setAdditionalProperties(?bool $additionalProperties): void
    {
        $this->additionalProperties = $additionalProperties;
    }
}
