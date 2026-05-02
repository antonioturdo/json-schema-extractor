<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

use Zeusi\JsonSchemaExtractor\Attribute\AdditionalProperties;

#[AdditionalProperties(true)]
class AdditionalPropertiesObject
{
    public string $id;
}
