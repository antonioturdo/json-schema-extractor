<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

final class StandardJsonSchemaMapperOptions
{
    public function __construct(
        public readonly JsonSchemaDialect $dialect = JsonSchemaDialect::Draft7,
        public readonly bool $includeSchemaKeyword = false,
        public readonly ClassReferenceStrategy $classReferenceStrategy = ClassReferenceStrategy::Definitions,
    ) {}
}
