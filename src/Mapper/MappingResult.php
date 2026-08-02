<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

use Zeusi\JsonSchemaExtractor\Model\JsonSchema\JsonSchemaInterface;

/**
 * The outcome of a mapping pass.
 *
 * Carries the JSON Schema document plus the reference pointers the mapper emitted
 * while building it. The pointers are only known to the mapper, because their shape
 * depends on the mapper's own naming and on the configured dialect. Returning them
 * alongside the schema lets callers relocate or rewrite referenced schemas without
 * re-deriving that mapping.
 */
final class MappingResult
{
    /**
     * @param array<string, class-string> $refs Reference pointer (as emitted in the
     *        document, e.g. `#/definitions/Company`) => the class it denotes.
     */
    public function __construct(
        public readonly JsonSchemaInterface $schema,
        public readonly array $refs,
    ) {}
}
