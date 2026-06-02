<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

use Zeusi\JsonSchemaExtractor\Serialization\State\ProjectionState;

/**
 * A reference to a non-canonical serialized view of another class.
 *
 * Emitted by a serialization strategy when a nested class must be projected under a
 * narrowing traversal state (e.g. a Symfony `ATTRIBUTES` slice). Canonical (un-narrowed)
 * references stay plain {@see ClassLikeType}. The referenced view is identified by class
 * plus the child state's view key; the concrete JSON Schema pointer is formatted by the mapper.
 */
final class SerializedViewReferenceType implements Type
{
    /**
     * @param class-string $className
     */
    public function __construct(
        public readonly string $className,
        public readonly ProjectionState $childState,
    ) {}
}
