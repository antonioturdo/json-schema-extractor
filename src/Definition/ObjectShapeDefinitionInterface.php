<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

/**
 * Represents an "object shape" definition that can be mapped to a JSON Schema object.
 *
 * This abstraction is meant to be shared by:
 * - class-backed DTO definitions (e.g. {@see ClassDefinition})
 * - inline object shapes (e.g. shaped arrays, Symfony Validator Collection), should we model them explicitly.
 *
 * The object shape is defined by a set of named {@see FieldDefinitionInterface} entries plus optional metadata.
 */
interface ObjectShapeDefinitionInterface
{
    /**
     * Returns the defined properties, keyed by their stable/internal name (typically the original PHP property name).
     *
     * @return array<string, FieldDefinitionInterface>
     */
    public function getProperties(): array;

    /**
     * Adds or replaces a property in the object shape.
     *
     * Implementations are expected to use {@see FieldDefinitionInterface::getName()} as the stable key.
     */
    public function addProperty(FieldDefinitionInterface $property): void;

    /**
     * Removes a property by its stable/internal name (typically the original PHP property name).
     */
    public function removeProperty(string $propertyName): void;

    /**
     * Gets a property by its stable/internal name (typically the original PHP property name).
     */
    public function getProperty(string $propertyName): ?FieldDefinitionInterface;

    /**
     * Returns a human-readable title for the object, if available.
     */
    public function getTitle(): ?string;

    /**
     * Sets a human-readable title for the object.
     */
    public function setTitle(?string $title): void;

    /**
     * Returns a human-readable description for the object, if available.
     */
    public function getDescription(): ?string;

    /**
     * Sets a human-readable description for the object.
     */
    public function setDescription(?string $description): void;

    /**
     * Controls JSON Schema `additionalProperties` for this object shape.
     *
     * - null: let the mapper decide defaults
     * - true: allow extra properties
     * - false: forbid extra properties
     */
    public function getAdditionalProperties(): ?bool;

    /**
     * Sets JSON Schema `additionalProperties` for this object shape.
     */
    public function setAdditionalProperties(?bool $additionalProperties): void;
}
