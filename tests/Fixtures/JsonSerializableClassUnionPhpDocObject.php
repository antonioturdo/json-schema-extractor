<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class JsonSerializableClassUnionPhpDocObject implements \JsonSerializable
{
    public function jsonSerialize(): BasicObject|string
    {
        return 'fallback';
    }
}
