<?php

namespace Zeusi\JsonSchemaExtractor\Model\JsonSchema;

interface JsonSchemaInterface extends \JsonSerializable
{
    /**
     * @return array<string, mixed>|object
     */
    public function jsonSerialize(): array|object;
}
