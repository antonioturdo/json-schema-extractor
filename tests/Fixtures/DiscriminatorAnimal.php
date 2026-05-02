<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

use Symfony\Component\Serializer\Attribute\DiscriminatorMap;

#[DiscriminatorMap(typeProperty: 'type', mapping: ['cat' => DiscriminatorCat::class, 'dog' => DiscriminatorDog::class])]
abstract class DiscriminatorAnimal {}
