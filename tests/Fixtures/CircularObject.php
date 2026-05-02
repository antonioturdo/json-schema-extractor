<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

class CircularObject
{
    public function __construct(
        public string $name,
        public ?CircularObject $child = null
    ) {}
}
