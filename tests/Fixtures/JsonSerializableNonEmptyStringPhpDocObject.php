<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class JsonSerializableNonEmptyStringPhpDocObject implements \JsonSerializable
{
    public string $internal = 'hidden';

    /**
     * @return non-empty-string
     */
    public function jsonSerialize(): string
    {
        return 'public payload';
    }
}
