<?php

namespace Zeusi\JsonSchemaExtractor\Model\Php;

use Zeusi\JsonSchemaExtractor\Model\Type\Type;

/**
 * @internal
 */
trait FieldDefinitionTrait
{
    private bool $required = false;

    private ?Type $type = null;

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): void
    {
        $this->required = $required;
    }

    public function getType(): ?Type
    {
        return $this->type;
    }

    public function setType(?Type $type): void
    {
        $this->type = $type;
    }
}
