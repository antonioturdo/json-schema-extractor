<?php

namespace Zeusi\JsonSchemaExtractor\Model\Serialized;

use Zeusi\JsonSchemaExtractor\Model\Type\Type;

/**
 * Represents a class projected to its serialized shape (one view).
 */
final class SerializedPayloadDefinition
{
    public function __construct(
        public readonly Type $type,
        /**
         * When true, this view is a bespoke one-off shape (e.g. a Symfony ATTRIBUTES
         * projection) and the mapper always inlines it, ignoring its reference strategy.
         * When false (default), the view is reusable and follows the configured strategy.
         * Set by the serialization strategy.
         */
        public readonly bool $inlineOnly = false,
    ) {}
}
