<?php

namespace Zeusi\JsonSchemaExtractor;

/**
 * The outcome of an extraction.
 *
 * Carries the generated JSON Schema plus the metadata collected while producing it.
 * Consumers read the properties; the library is the only producer. New metadata is
 * added as further readonly properties, which keeps the return surface extensible
 * without another breaking change.
 */
final class ExtractionResult
{
    /**
     * @param array<string, mixed>|object $schema The JSON Schema document.
     * @param array<string, class-string> $refs Reference pointer (as emitted in the
     *        document, e.g. `#/definitions/Company` on Draft-7 or `#/$defs/Company` on
     *        2020-12) => the class it denotes. Inlined schemas carry no pointer and are
     *        therefore absent. The root document (`#`) is not listed: callers already
     *        know the class they asked to extract.
     *
     * @internal Built by {@see SchemaExtractor}.
     */
    public function __construct(
        public readonly array|object $schema,
        public readonly array $refs,
    ) {}
}
