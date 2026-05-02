<?php

namespace Zeusi\JsonSchemaExtractor\Model\JsonSchema;

/**
 * Valid JSON Schema data types as defined in the specification.
 */
enum SchemaType: string
{
    case OBJECT  = 'object';
    case ARRAY   = 'array';
    case STRING  = 'string';
    case INTEGER = 'integer';
    case NUMBER  = 'number';
    case BOOLEAN = 'boolean';
    case NULL    = 'null';
}
