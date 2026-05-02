<?php

namespace Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor;

use phpDocumentor\Reflection\Type as PhpDocumentorType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;

interface PhpDocumentorTypeMapperInterface
{
    /**
     * @param \ReflectionClass<object> $context
     */
    public function parse(PhpDocumentorType $type, \ReflectionClass $context): Type;
}
