<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Controls how a {@see UnionType} should be rendered in JSON Schema.
 *
 * - ANY_OF: at least one branch must match (allows overlapping branches).
 * - ONE_OF: exactly one branch must match (fails when multiple branches match).
 */
enum UnionSemantics: string
{
    case AnyOf = 'anyOf';
    case OneOf = 'oneOf';
}
