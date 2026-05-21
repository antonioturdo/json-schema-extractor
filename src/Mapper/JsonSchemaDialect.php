<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

enum JsonSchemaDialect
{
    case Draft7;
    case Draft202012;

    public function schemaUri(): string
    {
        return match ($this) {
            self::Draft7 => 'http://json-schema.org/draft-07/schema#',
            self::Draft202012 => 'https://json-schema.org/draft/2020-12/schema',
        };
    }

    public function definitionsRefPrefix(): string
    {
        return match ($this) {
            self::Draft7 => '#/definitions/',
            self::Draft202012 => '#/$defs/',
        };
    }
}
