<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;

/**
 * Represents an inline/virtual object shape (no backing concrete PHP class).
 */
final class InlineObjectType implements TypeExpr
{
    public function __construct(
        public InlineObjectDefinition $shape,
    ) {}
}
