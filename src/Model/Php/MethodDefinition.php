<?php

namespace Zeusi\JsonSchemaExtractor\Model\Php;

use Zeusi\JsonSchemaExtractor\Model\Type\Type;

/**
 * Represents a method discovered from a PHP class.
 */
final class MethodDefinition
{
    public function __construct(
        private readonly string $methodName,
        private ?Type $returnType = null,
    ) {}

    public function getName(): string
    {
        return $this->methodName;
    }

    public function getReturnType(): ?Type
    {
        return $this->returnType;
    }

    public function setReturnType(?Type $returnType): void
    {
        $this->returnType = $returnType;
    }
}
