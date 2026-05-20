<?php

namespace Zeusi\JsonSchemaExtractor\Model\Serialized;

use Zeusi\JsonSchemaExtractor\Model\Type\Type;

/**
 * Represents the root payload after serialization projection.
 */
final class SerializedPayloadDefinition
{
    public function __construct(
        public readonly Type $type,
    ) {}
}
