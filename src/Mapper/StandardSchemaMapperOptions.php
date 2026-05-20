<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

final class StandardSchemaMapperOptions
{
    public function __construct(
        public readonly JsonSchemaDialect $dialect = JsonSchemaDialect::Draft7,
        public readonly bool $includeSchemaKeyword = false,
        public readonly ClassReferenceStrategy $classReferenceStrategy = ClassReferenceStrategy::Definitions,
    ) {}
}
