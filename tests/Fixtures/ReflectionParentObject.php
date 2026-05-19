<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

class ReflectionParentObject
{
    public function inheritedMethod(): self
    {
        return $this;
    }
}
