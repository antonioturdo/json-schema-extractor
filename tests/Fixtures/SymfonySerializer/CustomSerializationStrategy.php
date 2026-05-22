<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures\SymfonySerializer;

use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Serialization\SerializationStrategyInterface;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CustomNormalizedMoney;

final class CustomSerializationStrategy implements SerializationStrategyInterface
{
    public function __construct(
        private readonly SymfonySerializerStrategy $inner,
    ) {}

    public function project(ClassDefinition $definition, ExtractionContext $context): SerializedPayloadDefinition
    {
        $payload = $this->inner->project($definition, $context);
        if (!$payload->type instanceof SerializedObjectType) {
            return $payload;
        }

        $properties = [];
        foreach ($payload->type->shape->properties as $name => $property) {
            $properties[$name] = new SerializedPropertyDefinition(
                name: $property->name,
                required: $property->required,
                type: $this->projectCustomType($name, $property->type),
            );
        }

        return new SerializedPayloadDefinition(new SerializedObjectType(new SerializedObjectDefinition(
            name: $payload->type->shape->name,
            properties: $properties,
            title: $payload->type->shape->title,
            description: $payload->type->shape->description,
            additionalProperties: $payload->type->shape->additionalProperties,
            concreteClasses: $payload->type->shape->concreteClasses,
        )));
    }

    private function projectCustomType(string $propertyName, ?Type $type): ?Type
    {
        if ($type instanceof ClassLikeType && $type->name === CustomNormalizedMoney::class) {
            return new BuiltinType('string');
        }

        if ($propertyName === 'owner') {
            return new BuiltinType('string');
        }

        return $type;
    }
}
