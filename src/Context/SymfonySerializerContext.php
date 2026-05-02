<?php

namespace Zeusi\JsonSchemaGenerator\Context;

/**
 * Describes the environment/options used when serializing with Symfony Serializer.
 * This is intended to be used by serializer-aware enrichers.
 */
final class SymfonySerializerContext
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly array $context = [],
    ) {}
}
