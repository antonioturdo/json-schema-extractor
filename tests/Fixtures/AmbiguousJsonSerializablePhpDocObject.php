<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class AmbiguousJsonSerializablePhpDocObject implements \JsonSerializable
{
    /**
     * @return array{id: int}
     * @return array{name: string}
     */
    public function jsonSerialize(): array
    {
        return [];
    }
}
