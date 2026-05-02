<?php

namespace Zeusi\JsonSchemaExtractor\Serialization;

use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\JsonEncodeContext;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\FieldDefinitionInterface;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;

final class JsonEncodeSerializationStrategy implements SerializationStrategyInterface
{
    public function project(ClassDefinition $definition, ExtractionContext $context): SerializedObjectDefinition
    {
        $properties = [];
        foreach ($definition->getProperties() as $property) {
            $serializedProperty = $this->projectFieldDefinition($property, $context->find(JsonEncodeContext::class));
            $properties[$serializedProperty->name] = $serializedProperty;
        }

        return new SerializedObjectDefinition(
            name: $definition->getClassName(),
            properties: $properties,
            title: $definition->getTitle(),
            description: $definition->getDescription()
        );
    }

    private function projectFieldDefinition(FieldDefinitionInterface $field, ?JsonEncodeContext $context): SerializedPropertyDefinition
    {
        return new SerializedPropertyDefinition(
            name: $field->getName(),
            required: $field->isRequired(),
            type: $this->projectType($field->getType(), $context)
        );
    }

    private function projectInlineObjectDefinition(InlineObjectDefinition $definition, ?JsonEncodeContext $context): SerializedObjectDefinition
    {
        $properties = [];
        foreach ($definition->getProperties() as $property) {
            $serializedProperty = $this->projectFieldDefinition($property, $context);
            $properties[$serializedProperty->name] = $serializedProperty;
        }

        return new SerializedObjectDefinition(
            properties: $properties,
            title: $definition->getTitle(),
            description: $definition->getDescription(),
            additionalProperties: $definition->getAdditionalProperties()
        );
    }

    private function projectType(?Type $type, ?JsonEncodeContext $context): ?Type
    {
        if ($type instanceof ClassLikeType && $this->isDateTimeType($type->name)) {
            return $this->createDateTimeType();
        }

        return $this->copyType($type, $context);
    }

    /**
     * @param class-string $className
     */
    private function isDateTimeType(string $className): bool
    {
        return $className === \DateTimeInterface::class || is_subclass_of($className, \DateTimeInterface::class);
    }

    private function createDateTimeType(): Type
    {
        return new SerializedObjectType(new SerializedObjectDefinition(
            properties: [
                'date' => new SerializedPropertyDefinition(
                    name: 'date',
                    required: true,
                    type: new DecoratedType(
                        new BuiltinType('string'),
                        new TypeConstraints(pattern: '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$')
                    )
                ),
                'timezone_type' => new SerializedPropertyDefinition(
                    name: 'timezone_type',
                    required: true,
                    type: new DecoratedType(
                        new BuiltinType('int'),
                        new TypeConstraints(enum: [1, 2, 3])
                    )
                ),
                'timezone' => new SerializedPropertyDefinition(
                    name: 'timezone',
                    required: true,
                    type: new BuiltinType('string')
                ),
            ],
            additionalProperties: false
        ));
    }

    private function copyType(?Type $type, ?JsonEncodeContext $context): ?Type
    {
        return match (true) {
            $type === null => null,
            $type instanceof BuiltinType => new BuiltinType($type->name),
            $type instanceof ClassLikeType => new ClassLikeType($type->name),
            $type instanceof EnumType => new EnumType($type->className),
            $type instanceof ArrayType => new ArrayType($this->projectType($type->type, $context) ?? new UnknownType()),
            $type instanceof MapType => new MapType($this->projectType($type->type, $context) ?? new UnknownType()),
            $type instanceof InlineObjectType => new SerializedObjectType($this->projectInlineObjectDefinition($type->shape, $context)),
            $type instanceof DecoratedType => new DecoratedType(
                $this->projectType($type->type, $context) ?? new UnknownType(),
                $this->copyTypeConstraints($type->constraints),
                $this->copyTypeAnnotations($type->annotations, $context)
            ),
            $type instanceof UnionType => new UnionType(
                array_map(fn(Type $type): Type => $this->projectType($type, $context) ?? new UnknownType(), $type->types),
                $type->semantics
            ),
            $type instanceof IntersectionType => new IntersectionType(
                array_map(fn(Type $type): Type => $this->projectType($type, $context) ?? new UnknownType(), $type->types)
            ),
            $type instanceof UnknownType => new UnknownType(),
            default => throw new \LogicException(\sprintf('Unsupported Type "%s".', $type::class)),
        };
    }

    private function copyTypeConstraints(TypeConstraints $constraints): TypeConstraints
    {
        return new TypeConstraints(
            enum: $constraints->enum,
            minimum: $constraints->minimum,
            maximum: $constraints->maximum,
            exclusiveMinimum: $constraints->exclusiveMinimum,
            exclusiveMaximum: $constraints->exclusiveMaximum,
            multipleOf: $constraints->multipleOf,
            minLength: $constraints->minLength,
            maxLength: $constraints->maxLength,
            pattern: $constraints->pattern,
            minItems: $constraints->minItems,
            maxItems: $constraints->maxItems
        );
    }

    private function copyTypeAnnotations(?TypeAnnotations $annotations, ?JsonEncodeContext $context): ?TypeAnnotations
    {
        if ($annotations === null) {
            return null;
        }

        // Round-trip defaults through JSON so the schema default matches the value produced by json_encode().
        // Keep json_decode() in object mode to preserve the JSON distinction between [] and {}.
        $flags = $context instanceof JsonEncodeContext ? $context->flags : 0;
        $serializedDefault = json_encode($annotations->default, $flags);
        $default = false === $serializedDefault ? null : json_decode($serializedDefault, associative: false);

        return new TypeAnnotations(
            title: $annotations->title,
            description: $annotations->description,
            format: $annotations->format,
            deprecated: $annotations->deprecated,
            examples: $annotations->examples,
            default: $default
        );
    }
}
