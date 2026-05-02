<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;

/**
 * Represents a named field/member inside an object shape.
 *
 * A field can originate from a real PHP class property, or from an inline object shape
 * (e.g. shaped arrays, validator-driven collections, discriminator fields).
 *
 * This interface exists to allow distinguishing different "field origins" (class-backed vs inline) without
 * forcing a single concrete DTO type.
 */
interface FieldDefinitionInterface
{
    /**
     * Stable internal name of the field in the object shape (not necessarily the serialized output name).
     */
    public function getName(): string;

    /**
     * Name of the field as it will appear in the serialized output / JSON schema.
     */
    public function getSerializedName(): string;

    /**
     * Sets the serialized name (e.g. after applying a serializer name converter or attributes).
     */
    public function setSerializedName(string $serializedName): void;

    /**
     * Whether the field must be present in the serialized output.
     */
    public function isRequired(): bool;

    /**
     * Marks the field as required or optional in the serialized output.
     */
    public function setRequired(bool $required): void;

    /**
     * Optional type expression representation of the field.
     *
     * This is the primary type representation. It can model complex combinations of unions,
     * intersections, collection items, maps, and inline object shapes in a single tree.
     */
    public function getTypeExpr(): ?TypeExpr;

    /**
     * Sets the optional type expression for this field.
     */
    public function setTypeExpr(?TypeExpr $typeExpr): void;
}
