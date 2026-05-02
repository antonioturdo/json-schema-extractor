<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;

/**
 * Represents an inline/virtual object shape (no backing concrete PHP class).
 */
final class InlineObjectType implements Type
{
    public function __construct(
        public InlineObjectDefinition $shape,
    ) {}
}
