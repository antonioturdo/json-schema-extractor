<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

enum JsonSchemaDialect
{
    case Draft7;

    public function schemaUri(): string
    {
        return match ($this) {
            self::Draft7 => 'http://json-schema.org/draft-07/schema#',
        };
    }
}
