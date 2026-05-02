<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;
use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;
use Zeusi\JsonSchemaGenerator\Definition\TypeConstraints;

/**
 * Helper factory for building {@see TypeExpr} trees.
 *
 * This is purely syntactic sugar to improve readability when constructing types manually
 * (e.g. in tests or higher-level builders).
 */
final class Type
{
    private function __construct() {}

    public static function builtin(string $name): BuiltinType
    {
        return new BuiltinType($name);
    }

    public static function string(): BuiltinType
    {
        return new BuiltinType('string');
    }

    public static function int(): BuiltinType
    {
        return new BuiltinType('int');
    }

    public static function float(): BuiltinType
    {
        return new BuiltinType('float');
    }

    public static function bool(): BuiltinType
    {
        return new BuiltinType('bool');
    }

    public static function null(): BuiltinType
    {
        return new BuiltinType('null');
    }

    public static function mixed(): BuiltinType
    {
        return new BuiltinType('mixed');
    }

    public static function array(): BuiltinType
    {
        return new BuiltinType('array');
    }

    public static function iterable(): BuiltinType
    {
        return new BuiltinType('iterable');
    }

    public static function object(): BuiltinType
    {
        return new BuiltinType('object');
    }

    /**
     * @param class-string|interface-string $name
     */
    public static function classLike(string $name): ClassLikeType
    {
        return new ClassLikeType($name);
    }

    /**
     * @param class-string<\UnitEnum> $className
     */
    public static function enum(string $className): EnumType
    {
        return new EnumType($className);
    }

    public static function arrayOf(TypeExpr $type): ArrayType
    {
        return new ArrayType($type);
    }

    public static function mapOf(TypeExpr $type): MapType
    {
        return new MapType($type);
    }

    public static function inlineObject(InlineObjectDefinition $shape): InlineObjectType
    {
        return new InlineObjectType($shape);
    }

    public static function union(TypeExpr ...$types): UnionType
    {
        return new UnionType(array_values($types));
    }

    public static function intersection(TypeExpr ...$types): IntersectionType
    {
        return new IntersectionType(array_values($types));
    }

    public static function decorated(TypeExpr $type, ?TypeConstraints $constraints = null, ?TypeAnnotations $annotations = null): DecoratedType
    {
        return new DecoratedType($type, $constraints ?? new TypeConstraints(), $annotations);
    }
}
