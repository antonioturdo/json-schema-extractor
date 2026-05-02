<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class ComplexDiscriminatorContainer
{
    public function __construct(
        public DiscriminatorAnimal $primaryAnimal,
        public ?DiscriminatorAnimal $optionalAnimal = null,
        /** @var list<DiscriminatorAnimal> */
        public array $animals = [],
        /** @var array<string, DiscriminatorAnimal> */
        public array $animalsByName = [],
    ) {}
}
