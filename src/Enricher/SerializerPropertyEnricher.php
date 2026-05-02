<?php

namespace Zeusi\JsonSchemaGenerator\Enricher;

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
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Context\SymfonySerializerContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\InlineFieldDefinition;
use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\ClassLikeType;
use Zeusi\JsonSchemaGenerator\Definition\Type\DecoratedType;
use Zeusi\JsonSchemaGenerator\Definition\Type\InlineObjectType;
use Zeusi\JsonSchemaGenerator\Definition\Type\MapType;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExprUtils;
use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;
use Zeusi\JsonSchemaGenerator\Definition\TypeConstraints;
use Zeusi\JsonSchemaGenerator\JsonSchema\Format;

/**
 * Enriches the ClassDefinition using Symfony Serializer component.
 * Modifies property serialized names by taking into account #[SerializedName]
 * attributes and optional NameConverter fallback.
 */
class SerializerPropertyEnricher implements PropertyEnricherInterface
{
    public function __construct(
        private readonly ClassMetadataFactoryInterface $classMetadataFactory,
        private readonly ?NameConverterInterface $nameConverter = null
    ) {}

    public function enrich(ClassDefinition $definition, GenerationContext $context): void
    {
        $className = $definition->className;

        if (!class_exists($className)) {
            return;
        }

        $serializerContext = $context->find(SymfonySerializerContext::class) ?? new SymfonySerializerContext();

        try {
            $metadata = $this->classMetadataFactory->getMetadataFor($className);
            $attributesMetadata = $metadata->getAttributesMetadata();
            $discriminatorMapping = $this->resolveDiscriminatorMapping($metadata, $className);
        } catch (\Exception $e) {
            // If serializer fails to load metadata, we gracefully skip enrichment
            return;
        }

        $groups = $this->resolveGroups($serializerContext->context);

        $discriminatorPropertyName = null;
        if ($discriminatorMapping !== null) {
            $typeProperty = $discriminatorMapping->getTypeProperty();
            $mappedType = $discriminatorMapping->getMappedObjectType($className);

            if ($typeProperty !== '' && $mappedType !== null) {
                $discriminatorPropertyName = $typeProperty;
                $this->ensureDiscriminatorProperty($definition, $typeProperty, $mappedType);
            } elseif ($typeProperty !== '') {
                $this->setConcreteClassesFromDiscriminator($definition, $discriminatorMapping);
            }
        }

        $propertiesToRemove = [];
        foreach ($definition->properties as $propertyName => $propertyDef) {
            $newName = null;
            $isDiscriminatorProperty = $discriminatorPropertyName !== null && $propertyName === $discriminatorPropertyName;
            $attr = $attributesMetadata[$propertyName] ?? null;
            $propertyContext = $this->resolvePropertyContext($serializerContext->context, $attr, $groups);

            $propertyDef->setTypeExpr($this->rewriteKnownNormalizerExpr($propertyDef->getTypeExpr(), $propertyContext));

            // 1. Check for #[SerializedName]
            if ($attr !== null) {
                if ($attr->isIgnored()) {
                    $propertiesToRemove[] = $propertyName;
                    continue;
                }

                if ($groups !== null && !$isDiscriminatorProperty) {
                    $propertyGroups = $attr->getGroups();
                    if (empty($propertyGroups) || array_intersect($groups, $propertyGroups) === []) {
                        $propertiesToRemove[] = $propertyName;
                        continue;
                    }
                }

                $newName = $attr->getSerializedName();
            } elseif ($groups !== null && !$isDiscriminatorProperty) {
                $propertiesToRemove[] = $propertyName;
                continue;
            }

            // 2. Check for configured NameConverter (e.g. CamelCase to SnakeCase)
            if ($newName === null && $this->nameConverter !== null && !$isDiscriminatorProperty) {
                $newName = ($this->nameConverter instanceof AdvancedNameConverterInterface)
                    ? $this->nameConverter->normalize($propertyName, $className)
                    : $this->nameConverter->normalize($propertyName);
            }

            // 3. Apply the modified name if any manipulation occurred
            if ($newName !== null) {
                $propertyDef->setSerializedName($newName);
            }

            // 4. If skipNullValues is enabled, nullable properties are not required
            if ($this->shouldSkipNullValues($propertyContext) && $propertyDef->getTypeExpr() !== null && TypeExprUtils::allowsNull($propertyDef->getTypeExpr())) {
                $propertyDef->setRequired(false);
            }
        }

        foreach ($propertiesToRemove as $propertyName) {
            $definition->removeProperty($propertyName);
        }
    }

    private function ensureDiscriminatorProperty(ClassDefinition $definition, string $propertyName, string $mappedType): void
    {
        $propertyDefinition = $definition->getProperty($propertyName);

        if ($propertyDefinition === null) {
            $propertyDefinition = new InlineFieldDefinition(
                fieldName: $propertyName
            );
            $definition->addProperty($propertyDefinition);
        }

        $propertyDefinition->setRequired(true);
        $propertyDefinition->setSerializedName($propertyName);
        $propertyDefinition->setTypeExpr(TypeExprUtils::decorateNonNullBranches(
            $propertyDefinition->getTypeExpr() ?? new BuiltinType('string'),
            function (DecoratedType $decorated) use ($mappedType): void {
                if ($decorated->constraints->enum === []) {
                    $decorated->constraints->enum = [$mappedType];
                }
            }
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function rewriteKnownNormalizerExpr(?TypeExpr $expr, array $context): ?TypeExpr
    {
        return TypeExprUtils::rewrite(
            $expr,
            function (TypeExpr $type) use ($context): ?TypeExpr {
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
        );
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
     * @param class-string|interface-string $className
     */
    private function isDateTimeType(string $className): bool
    {
        return $className === \DateTimeInterface::class || is_subclass_of($className, \DateTimeInterface::class);
    }

    /**
     * @param class-string|interface-string $className
     */
    private function isDateTimeZoneType(string $className): bool
    {
        return is_a($className, \DateTimeZone::class, true);
    }

    /**
     * @param class-string|interface-string $className
     */
    private function isDateIntervalType(string $className): bool
    {
        return is_a($className, \DateInterval::class, true);
    }

    /**
     * @param class-string|interface-string $className
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
     * @param class-string|interface-string $className
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
     * @param class-string|interface-string $className
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
     * @param class-string|interface-string $className
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
     * @param class-string|interface-string $className
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
     * @param class-string|interface-string $className
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
     * @param class-string|interface-string $className
     */
    private function isSymfonyUuidType(string $className): bool
    {
        $uuidClass = 'Symfony\Component\Uid\Uuid';
        if (!class_exists($uuidClass)) {
            return false;
        }

        return is_a($className, $uuidClass, true);
    }

    private function createConstraintViolationListType(): TypeExpr
    {
        return $this->createProblemType(withViolations: true);
    }

    private function createProblemType(bool $withViolations = false): TypeExpr
    {
        $stringType = new BuiltinType('string');
        $mixedType = new BuiltinType('mixed');

        $problemShape = new InlineObjectDefinition(
            id: 'Symfony Problem',
            additionalProperties: false
        );
        $problemShape->addProperty($this->createInlineField('type', $stringType, true));
        $problemShape->addProperty($this->createInlineField('title', $stringType, true));
        $problemShape->addProperty($this->createInlineField('status', new BuiltinType('int'), true));
        $problemShape->addProperty($this->createInlineField('detail', $stringType, true));
        $problemShape->addProperty($this->createInlineField('class', $stringType));
        $problemShape->addProperty($this->createInlineField('trace', new ArrayType($mixedType)));

        if (!$withViolations) {
            return new InlineObjectType($problemShape);
        }

        $violationShape = new InlineObjectDefinition(
            id: 'Symfony ConstraintViolationList violation',
            additionalProperties: false
        );
        $violationShape->addProperty($this->createInlineField('propertyPath', $stringType, true));
        $violationShape->addProperty($this->createInlineField('title', $stringType, true));
        $violationShape->addProperty($this->createInlineField('template', $stringType, true));
        $violationShape->addProperty($this->createInlineField('parameters', new MapType($mixedType), true));
        $violationShape->addProperty($this->createInlineField('type', $stringType));
        $violationShape->addProperty($this->createInlineField('payload', new MapType($mixedType)));

        $problemShape->addProperty($this->createInlineField('status', new BuiltinType('int')));
        $problemShape->addProperty($this->createInlineField('detail', $stringType));
        $problemShape->addProperty($this->createInlineField('instance', $stringType));
        $problemShape->addProperty($this->createInlineField('violations', new ArrayType(new InlineObjectType($violationShape)), true));

        return new InlineObjectType($problemShape);
    }

    private function createInlineField(string $name, TypeExpr $type, bool $required = false): InlineFieldDefinition
    {
        $field = new InlineFieldDefinition($name, required: $required);
        $field->setTypeExpr($type);

        return $field;
    }

    // TypeExpr decorations are handled by {@see TypeExprUtils}.

    private function setConcreteClassesFromDiscriminator(ClassDefinition $definition, ClassDiscriminatorMapping $mapping): void
    {
        $classes = [];
        foreach ($mapping->getTypesMapping() as $className) {
            if (\is_string($className) && class_exists($className)) {
                /** @var class-string $className */
                $classes[$className] = $className;
            }
        }

        if ($classes !== []) {
            $definition->concreteClasses = array_values($classes);
        }
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
