<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;
use Zeusi\JsonSchemaGenerator\Definition\TypeConstraints;

/**
 * Decorates a {@see TypeExpr} with constraints and annotations.
 *
 * This is useful when metadata must apply to a specific sub-expression, e.g.:
 * - `array<Email>` where the `Email` items need a pattern constraint
 * - unions where only the non-null branch carries an enum constraint
 * - a nested structure where only a specific node needs a title/description
 *
 * The mapper is responsible for translating {@see TypeConstraints} and {@see TypeAnnotations} into
 * JSON Schema keywords.
 */
final class DecoratedType implements TypeExpr
{
    public function __construct(
        public TypeExpr $type,
        public TypeConstraints $constraints = new TypeConstraints(),
        public ?TypeAnnotations $annotations = null,
    ) {}
}
