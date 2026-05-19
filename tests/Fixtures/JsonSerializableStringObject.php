<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class JsonSerializableStringObject implements \JsonSerializable
{
    public string $internal = 'hidden';

    public function jsonSerialize(): string
    {
        return 'public payload';
    }
}
