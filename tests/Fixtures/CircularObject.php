<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Fixtures;

class CircularObject
{
    public function __construct(
        public string $name,
        public ?CircularObject $child = null
    ) {}
}
