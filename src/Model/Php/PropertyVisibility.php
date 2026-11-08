<?php

namespace Zeusi\JsonSchemaExtractor\Model\Php;

/**
 * Visibility of a class property.
 *
 * PHP exposes visibility only as `ReflectionProperty::IS_*` bitmask flags, so the
 * model carries its own enum.
 */
enum PropertyVisibility
{
    case Public;
    case Protected;
    case Private;
}
