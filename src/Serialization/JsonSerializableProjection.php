<?php

namespace Zeusi\JsonSchemaExtractor\Serialization;

use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;

final class JsonSerializableProjection
{
    /**
     * @param callable(?Type): ?Type $typeProjector
     */
    public static function project(ClassDefinition $definition, callable $typeProjector): ?SerializedObjectDefinition
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

        $projectedShape = self::extractSerializedObjectShape($typeProjector($jsonSerialize->getReturnType()));
        if ($projectedShape === null) {
            throw new \LogicException(\sprintf(
                'Cannot project JsonSerializable class "%s": jsonSerialize() return type metadata must describe an object shape.',
                $className
            ));
        }

        return new SerializedObjectDefinition(
            name: $definition->getClassName(),
            properties: $projectedShape->properties,
            title: $definition->getTitle() ?? $projectedShape->title,
            description: $definition->getDescription() ?? $projectedShape->description,
            additionalProperties: $projectedShape->additionalProperties
        );
    }

    private static function extractSerializedObjectShape(?Type $type): ?SerializedObjectDefinition
    {
        if ($type instanceof SerializedObjectType) {
            return $type->shape;
        }

        if ($type instanceof DecoratedType) {
            // Root object decorations do not have a dedicated representation on SerializedObjectDefinition yet.
            // Keep the object payload shape and intentionally drop wrapper-level constraints/annotations.
            return self::extractSerializedObjectShape($type->type);
        }

        return null;
    }
}
