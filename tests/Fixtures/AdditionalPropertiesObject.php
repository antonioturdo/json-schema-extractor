<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Fixtures;

use Zeusi\JsonSchemaGenerator\Attribute\AdditionalProperties;

#[AdditionalProperties(true)]
class AdditionalPropertiesObject
{
    public string $id;
}
