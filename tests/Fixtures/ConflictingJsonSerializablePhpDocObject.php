<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class ConflictingJsonSerializablePhpDocObject implements \JsonSerializable
{
    /**
     * @return array{id: int}
     */
    public function jsonSerialize(): string
    {
        return 'invalid-docblock';
    }
}
