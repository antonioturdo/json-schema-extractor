<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class JsonSerializableListPhpDocObject implements \JsonSerializable
{
    public string $internal = 'hidden';

    /**
     * @return list<string>
     */
    public function jsonSerialize(): array
    {
        return ['read', 'write'];
    }
}
