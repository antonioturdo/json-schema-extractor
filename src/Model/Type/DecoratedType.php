<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Decorates a {@see Type} with constraints and annotations.
 *
 * This is useful when metadata must apply to a specific sub-expression, e.g.:
 * - `array<Email>` where the `Email` items need a pattern constraint
 * - unions where only the non-null branch carries an enum constraint
 * - a nested structure where only a specific node needs a title/description
 *
 * The mapper is responsible for translating {@see TypeConstraints} and {@see TypeAnnotations} into
 * JSON Schema keywords.
 */
final class DecoratedType implements Type
{
    public function __construct(
        public Type             $type,
        public TypeConstraints  $constraints = new TypeConstraints(),
        public ?TypeAnnotations $annotations = null,
    ) {}
}
