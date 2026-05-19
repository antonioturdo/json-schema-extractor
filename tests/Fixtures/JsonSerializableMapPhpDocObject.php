<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class JsonSerializableMapPhpDocObject implements \JsonSerializable
{
    public string $internal = 'hidden';

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return ['visits' => 3];
    }
}
