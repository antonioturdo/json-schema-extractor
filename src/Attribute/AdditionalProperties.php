<?php

namespace Zeusi\JsonSchemaExtractor\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AdditionalProperties
{
    public function __construct(
        public bool $enabled
    ) {}
}
