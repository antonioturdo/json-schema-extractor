<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

/**
 * Represents an intermediate type expression for a field or value.
 *
 * A Type can model PHP-native types, class-like references, enum references,
 * collections, maps, inline object shapes, unions/intersections, and metadata
 * attached to specific nodes through DecoratedType.
 *
 * This is intentionally independent from JSON Schema keywords.
 * Mapping to JSON Schema is a responsibility of mappers.
 */
interface Type {}
