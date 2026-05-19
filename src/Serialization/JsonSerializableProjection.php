<?php

namespace Zeusi\JsonSchemaExtractor\Serialization;

use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;

final class JsonSerializableProjection
{
    /**
     * @param callable(?Type): ?Type $typeProjector
     */
    public static function project(ClassDefinition $definition, callable $typeProjector): ?SerializedPayloadDefinition
    {
        $className = $definition->getClassName();
        if (!class_exists($className) || !is_subclass_of($className, \JsonSerializable::class)) {
            return null;
        }

        $jsonSerialize = $definition->getMethod('jsonSerialize');
        if ($jsonSerialize === null) {
            throw new \LogicException(\sprintf(
                'Cannot project JsonSerializable class "%s": jsonSerialize() return type metadata is missing.',
                $className
            ));
        }

        $projectedType = $typeProjector($jsonSerialize->getReturnType());
        if ($projectedType === null || !self::isUsableRootType($projectedType)) {
            throw new \LogicException(\sprintf(
                'Cannot project JsonSerializable class "%s": jsonSerialize() return type metadata is not usable.',
                $className
            ));
        }

        return new SerializedPayloadDefinition(self::applyRootObjectMetadata($projectedType, $definition));
    }

    private static function isUsableRootType(Type $type): bool
    {
        if ($type instanceof DecoratedType) {
            return self::isUsableRootType($type->type);
        }

        if ($type instanceof UnknownType) {
            return false;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $innerType) {
                if (!self::isUsableRootType($innerType)) {
                    return false;
                }
            }

            return true;
        }

        if ($type instanceof BuiltinType) {
            return !\in_array($type->name, ['array', 'iterable', 'object', 'mixed'], true);
        }

        return true;
    }

    private static function applyRootObjectMetadata(Type $type, ClassDefinition $definition): Type
    {
        if ($type instanceof SerializedObjectType) {
            $shape = $type->shape;

            return new SerializedObjectType(new SerializedObjectDefinition(
                name: $definition->getClassName(),
                properties: $shape->properties,
                title: $definition->getTitle() ?? $shape->title,
                description: $definition->getDescription() ?? $shape->description,
                additionalProperties: $shape->additionalProperties,
                concreteClasses: $shape->concreteClasses
            ));
        }

        if ($type instanceof DecoratedType) {
            return new DecoratedType(
                self::applyRootObjectMetadata($type->type, $definition),
                $type->constraints,
                $type->annotations
            );
        }

        return $type;
    }
}
