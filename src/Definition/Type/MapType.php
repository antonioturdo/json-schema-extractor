<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

/**
 * Represents a map/dictionary with dynamic string keys, e.g. `array<string, Foo>`.
 *
 * Note: at the moment we only model string-keyed maps. Numeric-keyed arrays should be represented with {@see ArrayType}.
 */
final class MapType implements TypeExpr
{
    public function __construct(
        /**
         * Type of each value in the map.
         */
        public TypeExpr $type,
    ) {}
}
