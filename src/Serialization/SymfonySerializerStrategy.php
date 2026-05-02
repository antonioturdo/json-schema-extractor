<?php

namespace Zeusi\JsonSchemaExtractor\Serialization;

use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Mapping\AttributeMetadataInterface;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorMapping;
use Symfony\Component\Serializer\Mapping\ClassMetadataInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\AdvancedNameConverterInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DateIntervalNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\TranslatableNormalizer;
use Symfony\Component\Serializer\Normalizer\UidNormalizer;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Format;
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
use Zeusi\JsonSchemaExtractor\Model\Type\TypeUtils;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;

/**
 * Projects the ClassDefinition using Symfony Serializer component.
 *
 * Modifies property serialized names by taking into account #[SerializedName]
 * attributes and optional NameConverter fallback.
 */
class SymfonySerializerStrategy implements SerializationStrategyInterface
{
    public function __construct(
        private readonly ClassMetadataFactoryInterface $classMetadataFactory,
        private readonly ?NameConverterInterface $nameConverter = null
    ) {}

    public function project(ClassDefinition $definition, ExtractionContext $context): SerializedObjectDefinition
    {
        $className = $definition->getClassName();

        if (!class_exists($className)) {
            throw new \InvalidArgumentException(\sprintf(
                'Cannot project Symfony Serializer metadata for unknown class "%s".',
                $className
            ));
        }

        $serializerContext = $context->find(SymfonySerializerContext::class) ?? new SymfonySerializerContext();

        try {
            $metadata = $this->classMetadataFactory->getMetadataFor($className);
            $attributesMetadata = $metadata->getAttributesMetadata();
            $discriminatorMapping = $this->resolveDiscriminatorMapping($metadata, $className);
        } catch (\Exception $e) {
            throw new \RuntimeException(\sprintf(
                'Cannot project Symfony Serializer metadata for class "%s".',
                $className
            ), previous: $e);
        }

        $groups = $this->resolveGroups($serializerContext->context);
        $concreteClasses = [];

        $discriminator = null;
        $discriminatorPropertySeen = false;
        if ($discriminatorMapping !== null) {
            $typeProperty = $discriminatorMapping->getTypeProperty();
            $mappedType = $discriminatorMapping->getMappedObjectType($className);

            if ($typeProperty !== '' && $mappedType !== null) {
                $discriminator = [
                    'propertyName' => $typeProperty,
                    'mappedType' => $mappedType,
                ];
            } elseif ($typeProperty !== '') {
                $concreteClasses = $this->concreteClassesFromDiscriminator($discriminatorMapping);
            }
        }

        $properties = [];
        foreach ($definition->getProperties() as $propertyName => $propertyDefinition) {
            $newName = null;
            $isDiscriminatorProperty = $discriminator !== null && $propertyName === $discriminator['propertyName'];
            if ($isDiscriminatorProperty) {
                $discriminatorPropertySeen = true;
            }

            $attributeMetadata = $attributesMetadata[$propertyName] ?? null;
            $propertyContext = $this->resolvePropertyContext($serializerContext->context, $attributeMetadata, $groups);

            if ($attributeMetadata !== null) {
                if ($attributeMetadata->isIgnored()) {
                    continue;
                }

                if ($groups !== null && !$isDiscriminatorProperty) {
                    $propertyGroups = $attributeMetadata->getGroups();
                    if (empty($propertyGroups) || array_intersect($groups, $propertyGroups) === []) {
                        continue;
                    }
                }

                $newName = $attributeMetadata->getSerializedName();
            } elseif ($groups !== null && !$isDiscriminatorProperty) {
                continue;
            }

            $projectedType = TypeUtils::rewrite(
                $this->copyType($propertyDefinition->getType(), $propertyContext),
                fn(Type $type): ?Type => $this->rewriteKnownNormalizerExpr($type, $propertyContext)
            );

            if ($newName === null && $this->nameConverter !== null && !$isDiscriminatorProperty) {
                if (interface_exists(AdvancedNameConverterInterface::class) && $this->nameConverter instanceof AdvancedNameConverterInterface) {
                    $newName = $this->nameConverter->normalize($propertyName, $className);
                } else {
                    $newName = $this->nameConverter->normalize($propertyName);
                }
            }

            $required = $propertyDefinition->isRequired();
            if ($this->shouldSkipNullValues($propertyContext) && $projectedType !== null && TypeUtils::allowsNull($projectedType)) {
                $required = false;
            }

            if ($isDiscriminatorProperty) {
                $required = true;
                $newName = $propertyName;
                $projectedType = $this->applyDiscriminatorType($projectedType, $discriminator['mappedType']);
            }

            $name = $newName ?? $propertyName;
            $projectedProperty = new SerializedPropertyDefinition(
                name: $name,
                required: $required,
                type: $projectedType
            );
            $properties[$projectedProperty->name] = $projectedProperty;
        }

        if ($discriminator !== null && !$discriminatorPropertySeen) {
            $projectedProperty = $this->createDiscriminatorProperty($discriminator['propertyName'], $discriminator['mappedType']);
            $properties[$projectedProperty->name] = $projectedProperty;
        }

        return new SerializedObjectDefinition(
            name: $definition->getClassName(),
            properties: $properties,
            title: $definition->getTitle(),
            description: $definition->getDescription(),
            concreteClasses: $concreteClasses
        );
    }

    private function createDiscriminatorProperty(string $propertyName, string $mappedType): SerializedPropertyDefinition
    {
        return new SerializedPropertyDefinition(
            name: $propertyName,
            required: true,
            type: $this->applyDiscriminatorType(new BuiltinType('string'), $mappedType)
        );
    }

    private function applyDiscriminatorType(?Type $type, string $mappedType): Type
    {
        $fallbackType = new BuiltinType('string');

        return TypeUtils::decorateNonNullBranches(
            $type ?? $fallbackType,
            static function (DecoratedType $decorated) use ($mappedType): void {
                $decorated->constraints->enum = [$mappedType];
            }
        ) ?? $fallbackType;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function rewriteKnownNormalizerExpr(Type $type, array $context): ?Type
    {
        if (!$type instanceof ClassLikeType) {
            return null;
        }

        if ($this->isDateTimeType($type->name)) {
            return new DecoratedType(
                new BuiltinType('string'),
                new TypeConstraints(),
                new TypeAnnotations(format: $this->dateTimeSchemaFormat($context))
            );
        }

        if ($this->isDateTimeZoneType($type->name)) {
            return new DecoratedType(new BuiltinType('string'));
        }

        if ($this->isDateIntervalType($type->name)) {
            return new DecoratedType(
                new BuiltinType('string'),
                new TypeConstraints(),
                new TypeAnnotations(format: $this->dateIntervalSchemaFormat($context))
            );
        }

        if ($this->isSymfonyUidType($type->name)) {
            return new DecoratedType(
                new BuiltinType('string'),
                new TypeConstraints(),
                new TypeAnnotations(format: $this->uidSchemaFormat($type->name, $context))
            );
        }

        if ($this->isTranslatableType($type->name)) {
            return new DecoratedType(new BuiltinType('string'));
        }

        if ($this->isDataUriType($type->name)) {
            return new DecoratedType(
                new BuiltinType('string'),
                new TypeConstraints(pattern: '^data:')
            );
        }

        if ($this->isConstraintViolationListType($type->name)) {
            return $this->createConstraintViolationListType();
        }

        if ($this->isFlattenExceptionType($type->name)) {
            return $this->createProblemType();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $globalContext
     * @param array<string>|null $groups
     * @return array<string, mixed>
     */
    private function resolvePropertyContext(array $globalContext, ?AttributeMetadataInterface $attributeMetadata, ?array $groups): array
    {
        if ($attributeMetadata === null) {
            return $globalContext;
        }

        return array_merge($globalContext, $attributeMetadata->getNormalizationContextForGroups($groups ?? []));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string>|null
     */
    private function resolveGroups(array $context): ?array
    {
        $groups = $context[AbstractNormalizer::GROUPS] ?? null;
        if (\is_string($groups)) {
            return [$groups];
        }

        if (\is_array($groups)) {
            return array_values(array_filter($groups, static fn(mixed $group): bool => \is_string($group)));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function shouldSkipNullValues(array $context): bool
    {
        return ($context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ?? false) === true;
    }

    /**
     * @param class-string $className
     */
    private function isDateTimeType(string $className): bool
    {
        return $className === \DateTimeInterface::class || is_subclass_of($className, \DateTimeInterface::class);
    }

    /**
     * @param class-string $className
     */
    private function isDateTimeZoneType(string $className): bool
    {
        return is_a($className, \DateTimeZone::class, true);
    }

    /**
     * @param class-string $className
     */
    private function isDateIntervalType(string $className): bool
    {
        return is_a($className, \DateInterval::class, true);
    }

    /**
     * @param class-string $className
     */
    private function isSymfonyUidType(string $className): bool
    {
        $abstractUidClass = 'Symfony\Component\Uid\AbstractUid';
        if (!class_exists($abstractUidClass)) {
            return false;
        }

        return is_a($className, $abstractUidClass, true);
    }

    /**
     * @param class-string $className
     */
    private function isConstraintViolationListType(string $className): bool
    {
        $constraintViolationListInterface = 'Symfony\Component\Validator\ConstraintViolationListInterface';
        if (!interface_exists($constraintViolationListInterface)) {
            return false;
        }

        return is_a($className, $constraintViolationListInterface, true);
    }

    /**
     * @param class-string $className
     */
    private function isTranslatableType(string $className): bool
    {
        $translatableInterface = 'Symfony\Contracts\Translation\TranslatableInterface';
        if (!interface_exists($translatableInterface) || !class_exists(TranslatableNormalizer::class)) {
            return false;
        }

        return is_a($className, $translatableInterface, true);
    }

    /**
     * @param class-string $className
     */
    private function isFlattenExceptionType(string $className): bool
    {
        $flattenExceptionClass = 'Symfony\Component\ErrorHandler\Exception\FlattenException';
        if (!class_exists($flattenExceptionClass)) {
            return false;
        }

        return is_a($className, $flattenExceptionClass, true);
    }

    /**
     * @param class-string $className
     */
    private function isDataUriType(string $className): bool
    {
        return is_a($className, \SplFileInfo::class, true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function dateTimeSchemaFormat(array $context): ?string
    {
        $format = $context[DateTimeNormalizer::FORMAT_KEY] ?? \DateTimeInterface::RFC3339;
        if (!\is_string($format)) {
            return Format::DateTime->value;
        }

        if (\in_array($format, [\DateTimeInterface::RFC3339, \DateTimeInterface::RFC3339_EXTENDED, \DateTimeInterface::ATOM], true)) {
            return Format::DateTime->value;
        }

        if ($format === 'Y-m-d') {
            return Format::Date->value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function dateIntervalSchemaFormat(array $context): ?string
    {
        $format = $context[DateIntervalNormalizer::FORMAT_KEY] ?? '%rP%yY%mM%dDT%hH%iM%sS';
        if (!\is_string($format)) {
            return Format::Duration->value;
        }

        if ($format === '%rP%yY%mM%dDT%hH%iM%sS') {
            return Format::Duration->value;
        }

        return null;
    }

    /**
     * @param class-string $className
     * @param array<string, mixed> $context
     */
    private function uidSchemaFormat(string $className, array $context): ?string
    {
        if (!$this->isSymfonyUuidType($className)) {
            return null;
        }

        $format = $context[UidNormalizer::NORMALIZATION_FORMAT_KEY] ?? UidNormalizer::NORMALIZATION_FORMAT_CANONICAL;
        if (!\is_string($format)) {
            return Format::Uuid->value;
        }

        if (\in_array($format, [UidNormalizer::NORMALIZATION_FORMAT_CANONICAL, UidNormalizer::NORMALIZATION_FORMAT_RFC4122], true)) {
            return Format::Uuid->value;
        }

        return null;
    }

    /**
     * @param class-string $className
     */
    private function isSymfonyUuidType(string $className): bool
    {
        $uuidClass = 'Symfony\Component\Uid\Uuid';
        if (!class_exists($uuidClass)) {
            return false;
        }

        return is_a($className, $uuidClass, true);
    }

    private function createConstraintViolationListType(): Type
    {
        return $this->createProblemType(withViolations: true);
    }

    private function createProblemType(bool $withViolations = false): Type
    {
        $stringType = new BuiltinType('string');
        $mixedType = new BuiltinType('mixed');

        $problemProperties = [
            'type' => $this->createSerializedProperty('type', $stringType, true),
            'title' => $this->createSerializedProperty('title', $stringType, true),
            'status' => $this->createSerializedProperty('status', new BuiltinType('int'), true),
            'detail' => $this->createSerializedProperty('detail', $stringType, true),
            'class' => $this->createSerializedProperty('class', $stringType),
            'trace' => $this->createSerializedProperty('trace', new ArrayType($mixedType)),
        ];

        if (!$withViolations) {
            return new SerializedObjectType(new SerializedObjectDefinition(
                properties: $problemProperties,
                additionalProperties: false
            ));
        }

        $violationShape = new SerializedObjectDefinition(
            properties: [
                'propertyPath' => $this->createSerializedProperty('propertyPath', $stringType, true),
                'title' => $this->createSerializedProperty('title', $stringType, true),
                'template' => $this->createSerializedProperty('template', $stringType, true),
                'parameters' => $this->createSerializedProperty('parameters', new MapType($mixedType), true),
                'type' => $this->createSerializedProperty('type', $stringType),
                'payload' => $this->createSerializedProperty('payload', new MapType($mixedType)),
            ],
            additionalProperties: false
        );

        $problemProperties['status'] = $this->createSerializedProperty('status', new BuiltinType('int'));
        $problemProperties['detail'] = $this->createSerializedProperty('detail', $stringType);
        $problemProperties['instance'] = $this->createSerializedProperty('instance', $stringType);
        $problemProperties['violations'] = $this->createSerializedProperty('violations', new ArrayType(new SerializedObjectType($violationShape)), true);

        return new SerializedObjectType(new SerializedObjectDefinition(
            properties: $problemProperties,
            additionalProperties: false
        ));
    }

    private function createSerializedProperty(string $name, Type $type, bool $required = false): SerializedPropertyDefinition
    {
        return new SerializedPropertyDefinition(
            name: $name,
            required: $required,
            type: $type
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function projectFieldDefinition(FieldDefinitionInterface $field, array $context = []): SerializedPropertyDefinition
    {
        return new SerializedPropertyDefinition(
            name: $field->getName(),
            required: $field->isRequired(),
            type: $this->copyType($field->getType(), $context)
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function copyInlineObjectDefinition(InlineObjectDefinition $definition, array $context): SerializedObjectDefinition
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

    /**
     * @param array<string, mixed> $context
     */
    private function copyType(?Type $type, array $context = []): ?Type
    {
        return match (true) {
            $type === null => null,
            $type instanceof BuiltinType => new BuiltinType($type->name),
            $type instanceof ClassLikeType => new ClassLikeType($type->name),
            $type instanceof EnumType => new EnumType($type->className),
            $type instanceof ArrayType => new ArrayType($this->copyType($type->type, $context) ?? new UnknownType()),
            $type instanceof MapType => new MapType($this->copyType($type->type, $context) ?? new UnknownType()),
            $type instanceof InlineObjectType => new SerializedObjectType($this->copyInlineObjectDefinition($type->shape, $context)),
            $type instanceof DecoratedType => new DecoratedType(
                $this->copyType($type->type, $context) ?? new UnknownType(),
                $this->copyTypeConstraints($type->constraints),
                $this->copyTypeAnnotations($type->annotations, $context)
            ),
            $type instanceof UnionType => new UnionType(
                array_map(fn(Type $type): Type => $this->copyType($type, $context) ?? new UnknownType(), $type->types),
                $type->semantics
            ),
            $type instanceof IntersectionType => new IntersectionType(
                array_map(fn(Type $type): Type => $this->copyType($type, $context) ?? new UnknownType(), $type->types)
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

    /**
     * @param array<string, mixed> $context
     */
    private function copyTypeAnnotations(?TypeAnnotations $annotations, array $context): ?TypeAnnotations
    {
        if ($annotations === null) {
            return null;
        }

        // Round-trip defaults through Symfony's JSON encoder options so the schema default
        // matches the JSON value produced by the configured serializer context.
        // Keep json_decode() in object mode to preserve the JSON distinction between [] and {}.
        $serializedDefault = json_encode($annotations->default, $this->jsonEncodeOptions($context));
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

    /**
     * @param array<string, mixed> $context
     */
    private function jsonEncodeOptions(array $context): int
    {
        $options = $context[JsonEncode::OPTIONS] ?? \JSON_PRESERVE_ZERO_FRACTION;

        return \is_int($options) ? $options : \JSON_PRESERVE_ZERO_FRACTION;
    }

    /**
     * @return list<class-string>
     */
    private function concreteClassesFromDiscriminator(ClassDiscriminatorMapping $mapping): array
    {
        $classes = [];
        foreach ($mapping->getTypesMapping() as $className) {
            if (\is_string($className) && class_exists($className)) {
                /** @var class-string $className */
                $classes[$className] = $className;
            }
        }

        return array_values($classes);
    }

    /**
     * @param class-string $className
     */
    private function resolveDiscriminatorMapping(ClassMetadataInterface $metadata, string $className): ?ClassDiscriminatorMapping
    {
        $mapping = $metadata->getClassDiscriminatorMapping();
        if ($mapping !== null) {
            return $mapping;
        }

        $reflectionClass = new \ReflectionClass($className);
        $parent = $reflectionClass->getParentClass();
        while ($parent !== false) {
            try {
                $parentMetadata = $this->classMetadataFactory->getMetadataFor($parent->getName());
                $mapping = $parentMetadata->getClassDiscriminatorMapping();
                if ($mapping !== null) {
                    return $mapping;
                }
            } catch (\Exception $e) {
                // ignore and continue walking parents
            }

            $parent = $parent->getParentClass();
        }

        return null;
    }
}
