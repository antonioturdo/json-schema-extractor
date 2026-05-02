<?php

namespace Zeusi\JsonSchemaExtractor\Model\Php;

use Zeusi\JsonSchemaExtractor\Model\Type\Type;

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
     * Stable internal name of the field in the source object shape.
     */
    public function getName(): string;

    /**
     * Whether the field must be present in the serialized output.
     */
    public function isRequired(): bool;

    /**
     * Marks the field as required or optional in the serialized output.
     */
    public function setRequired(bool $required): void;

    /**
     * Optional type representation of the field.
     *
     * This is the primary type representation. It can model complex combinations of unions,
     * intersections, collection items, maps, and inline object shapes in a single tree.
     */
    public function getType(): ?Type;

    /**
     * Sets the optional type for this field.
     */
    public function setType(?Type $type): void;
}
