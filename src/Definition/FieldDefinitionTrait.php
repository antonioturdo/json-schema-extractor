<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;

/**
 * @internal
 */
trait FieldDefinitionTrait
{
    /**
     * Optional override for the serialized field name.
     *
     * When null, the effective serialized name is the field's stable/internal name returned by getName().
     */
    private ?string $serializedName = null;

    private bool $required = false;

    private ?TypeExpr $typeExpr = null;

    public function getSerializedName(): string
    {
        return $this->serializedName ?? $this->getName();
    }

    public function setSerializedName(string $serializedName): void
    {
        $this->serializedName = $serializedName;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): void
    {
        $this->required = $required;
    }

    public function getTypeExpr(): ?TypeExpr
    {
        return $this->typeExpr;
    }

    public function setTypeExpr(?TypeExpr $typeExpr): void
    {
        $this->typeExpr = $typeExpr;
    }
}
