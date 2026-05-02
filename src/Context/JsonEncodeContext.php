<?php

namespace Zeusi\JsonSchemaExtractor\Context;

final class JsonEncodeContext
{
    public function __construct(
        public readonly int $flags = 0,
    ) {}
}
