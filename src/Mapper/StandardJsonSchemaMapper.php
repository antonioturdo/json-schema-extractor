<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

use Zeusi\JsonSchemaExtractor\Model\JsonSchema\JsonSchema;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\SchemaType;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedReferenceType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionSemantics;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;

/**
 * The standard mapper that translates the serialized payload definition into a JSON Schema document.
 *
 * It uses the internal JsonSchema object to easily build and compose the output.
 */
class StandardJsonSchemaMapper implements JsonSchemaMapperInterface
{
    /** @var callable(string): SerializedPayloadDefinition */
    private $payloadProvider;

    /** @var array<string, JsonSchema> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $definitionNamesByClass = [];

    /** @var array<string, true> */
    private array $buildingDefinitions = [];

    private int $mapDepth = 0;

    private ?string $rootClassName = null;

    public function __construct(
        private readonly StandardJsonSchemaMapperOptions $options = new StandardJsonSchemaMapperOptions()
    ) {}

    public function map(SerializedPayloadDefinition $definition, callable $payloadProvider): JsonSchema
    {
        $isRootMap = $this->mapDepth === 0;
        if ($isRootMap) {
            $this->resetDefinitionsState($this->extractRootObjectDefinition($definition->type)?->name);
        }

        $this->payloadProvider = $payloadProvider;

        ++$this->mapDepth;
        try {
            $schema = $this->mapType($definition->type);
        } finally {
            --$this->mapDepth;
        }

        if ($isRootMap) {
            if ($this->options->includeSchemaKeyword) {
                $schema->setSchema($this->options->dialect->schemaUri());
            }

            if ($this->options->classReferenceStrategy === ClassReferenceStrategy::Definitions && $this->definitions !== []) {
                $this->applyDefinitions($schema);
            }

            $this->rootClassName = null;
        }

        return $schema;
    }

    private function extractRootObjectDefinition(Type $type): ?SerializedObjectDefinition
    {
        if ($type instanceof SerializedObjectType) {
            return $type->shape;
        }

        if ($type instanceof DecoratedType) {
            return $this->extractRootObjectDefinition($type->type);
        }

        return null;
    }

    /**
     * @param list<class-string> $concreteClasses
     */
    private function mapConcreteClasses(array $concreteClasses): JsonSchema
    {
        $schemas = [];
        foreach ($concreteClasses as $className) {
            if ($this->options->classReferenceStrategy === ClassReferenceStrategy::Definitions) {
                $this->registerDefinition($className);
                $schemas[] = (new JsonSchema())->setRef($this->definitionRef($className));
            } else {
                $schemas[] = $this->provideNestedSchema($className);
            }
        }

        return (new JsonSchema())->setOneOf($schemas);
    }

    private function mapObjectShape(SerializedObjectDefinition $definition): JsonSchema
    {
        $schema = (new JsonSchema())
            ->setType(SchemaType::OBJECT);

        if ($definition->additionalProperties !== null) {
            $schema->setAdditionalProperties($definition->additionalProperties);
        }

        if ($definition->title !== null) {
            $schema->setTitle($definition->title);
        }

        if ($definition->description !== null) {
            $schema->setDescription($definition->description);
        }

        foreach ($definition->properties as $property) {
            $type = $property->type;
            if ($type !== null) {
                $propertySchema = $this->mapType($type);
            } else {
                $propertySchema = new JsonSchema();
            }

            $schema->addProperty(
                $property->name,
                $propertySchema,
                $property->required
            );
        }

        return $schema;
    }

    private function mapType(Type $type): JsonSchema
    {
        if ($type instanceof DecoratedType) {
            $schema = $this->mapType($type->type);
            $this->applyTypeConstraints($schema, $type->constraints);
            if ($type->annotations !== null) {
                $this->applyTypeAnnotations($schema, $type->annotations);
            }
            return $schema;
        }

        if ($type instanceof UnionType) {
            return $this->mapUnionType($type);
        }

        if ($type instanceof IntersectionType) {
            return $this->mapIntersectionType($type);
        }

        if ($type instanceof ArrayType) {
            $schema = (new JsonSchema())->setType(SchemaType::ARRAY);
            $schema->setItems($this->mapType($type->type));
            return $schema;
        }

        if ($type instanceof MapType) {
            $valueSchema = $this->mapType($type->type);
            return (new JsonSchema())
                ->setType(SchemaType::OBJECT)
                ->setAdditionalProperties($valueSchema);
        }

        if ($type instanceof InlineObjectType) {
            throw new \LogicException('InlineObjectType must be projected to SerializedObjectType before schema mapping.');
        }

        if ($type instanceof SerializedObjectType) {
            if ($type->shape->concreteClasses !== []) {
                return $this->mapConcreteClasses($type->shape->concreteClasses);
            }

            return $this->mapObjectShape($type->shape);
        }

        if ($type instanceof SerializedReferenceType) {
            return (new JsonSchema())->setRef($this->recursionRef($type->className));
        }

        if ($type instanceof BuiltinType) {
            return $this->mapBuiltinType($type);
        }

        if ($type instanceof UnknownType) {
            return new JsonSchema();
        }

        if ($type instanceof EnumType) {
            return $this->mapEnumType($type);
        }

        if ($type instanceof ClassLikeType) {
            return $this->mapClassLikeType($type);
        }

        return new JsonSchema();
    }

    private function mapBuiltinType(BuiltinType $type): JsonSchema
    {
        // mixed value => empty schema
        if ($type->name === 'mixed') {
            return new JsonSchema();
        }

        $schemaType = match ($type->name) {
            'int' => SchemaType::INTEGER,
            'float' => SchemaType::NUMBER,
            'bool' => SchemaType::BOOLEAN,
            'string' => SchemaType::STRING,
            'null' => SchemaType::NULL,
            'array', 'iterable' => SchemaType::ARRAY,
            'object' => SchemaType::OBJECT,
            default => throw new \LogicException(\sprintf('Unsupported builtin type "%s".', $type->name)),
        };

        $schema = (new JsonSchema())->setType($schemaType);

        if ($type->name === 'array' || $type->name === 'iterable') {
            $schema->setItems(new JsonSchema());
        }

        if ($type->name === 'object') {
            $schema->setAdditionalProperties(true);
        }

        return $schema;
    }

    private function mapClassLikeType(ClassLikeType $type): JsonSchema
    {
        $className = $type->name;

        if (interface_exists($className)) {
            throw new \RuntimeException(\sprintf('Cannot generate JSON Schema for interface "%s" automatically: 
                    interfaces do not define properties or a concrete shape. Provide a concrete DTO class, 
                    or add an enricher that maps this interface to one or more concrete implementations (oneOf), 
                    or to an untyped object (additionalProperties: true).', $className));
        }

        if ($this->options->classReferenceStrategy === ClassReferenceStrategy::Definitions) {
            if ($className === $this->rootClassName) {
                return (new JsonSchema())->setRef('#');
            }

            $this->registerDefinition($className);
            return (new JsonSchema())->setRef($this->definitionRef($className));
        }

        return $this->provideNestedSchema($className);
    }

    private function mapEnumType(EnumType $type): JsonSchema
    {
        if ($this->options->classReferenceStrategy === ClassReferenceStrategy::Definitions) {
            $this->registerEnumDefinition($type->className);
            return (new JsonSchema())->setRef($this->definitionRef($type->className));
        }

        return $this->buildEnumSchema($type->className);
    }

    private function applyDefinitions(JsonSchema $schema): void
    {
        match ($this->options->dialect) {
            JsonSchemaDialect::Draft7 => $schema->setDefinitions($this->definitions),
            JsonSchemaDialect::Draft202012 => $schema->setDefs($this->definitions),
        };
    }

    /**
     * @param class-string $className
     */
    private function provideNestedSchema(string $className): JsonSchema
    {
        $payload = ($this->payloadProvider)($className);
        return $this->mapType($payload->type);
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    private function buildEnumSchema(string $enumClass): JsonSchema
    {
        $reflectionEnum = new \ReflectionEnum($enumClass);
        $cases = [];
        foreach ($reflectionEnum->getCases() as $case) {
            $cases[] = $case instanceof \ReflectionEnumBackedCase ? $case->getBackingValue() : $case->getName();
        }

        return (new JsonSchema())
            ->setType(!empty($cases) && \is_int($cases[0]) ? SchemaType::INTEGER : SchemaType::STRING)
            ->setEnum($cases);
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    private function registerEnumDefinition(string $enumClass): void
    {
        $definitionName = $this->definitionName($enumClass);
        if (isset($this->definitions[$definitionName])) {
            return;
        }

        $this->definitions[$definitionName] = $this->buildEnumSchema($enumClass);
    }

    private function resetDefinitionsState(?string $rootClassName): void
    {
        $this->definitions = [];
        $this->definitionNamesByClass = [];
        $this->buildingDefinitions = [];
        $this->rootClassName = $rootClassName;
    }

    /**
     * @param class-string $className
     */
    private function registerDefinition(string $className): void
    {
        $definitionName = $this->definitionName($className);
        if (isset($this->definitions[$definitionName]) || isset($this->buildingDefinitions[$className])) {
            return;
        }

        $this->buildingDefinitions[$className] = true;
        $this->definitions[$definitionName] = $this->provideNestedSchema($className);
        unset($this->buildingDefinitions[$className]);
    }

    /**
     * @param class-string $className
     */
    private function recursionRef(string $className): string
    {
        return '#/components/schemas/' . str_replace('\\', '.', $className);
    }

    /**
     * @param class-string $className
     */
    private function definitionRef(string $className): string
    {
        return $this->options->dialect->definitionsRefPrefix() . $this->definitionName($className);
    }

    /**
     * @param class-string $className
     */
    private function definitionName(string $className): string
    {
        if (isset($this->definitionNamesByClass[$className])) {
            return $this->definitionNamesByClass[$className];
        }

        $shortName = (new \ReflectionClass($className))->getShortName();
        $definitionName = $shortName;

        $existingClassName = array_search($definitionName, $this->definitionNamesByClass, true);
        if ($existingClassName !== false && $existingClassName !== $className) {
            $definitionName = str_replace('\\', '.', $className);
        }

        $this->definitionNamesByClass[$className] = $definitionName;

        return $definitionName;
    }

    private function mapUnionType(UnionType $type): JsonSchema
    {
        $schemas = [];

        foreach ($type->types as $subType) {
            $schema = $this->mapType($subType);

            // Fingerprint to avoid duplicates
            $fingerprint = json_encode($schema->jsonSerialize());
            $schemas[$fingerprint] = $schema;
        }

        $finalSchemas = array_values($schemas);

        if (\count($finalSchemas) === 1) {
            return $finalSchemas[0];
        }

        $collapsedTypes = $this->collapseToTypeArrayIfPossible($finalSchemas);
        if ($collapsedTypes !== null) {
            return (new JsonSchema())->setTypeUnion($collapsedTypes);
        }

        $semantics = $type->semantics ?? $this->inferUnionSemantics($finalSchemas);
        return match ($semantics) {
            UnionSemantics::OneOf => (new JsonSchema())->setOneOf($finalSchemas),
            UnionSemantics::AnyOf => (new JsonSchema())->setAnyOf($finalSchemas),
        };
    }

    /**
     * @param array<JsonSchema> $schemas
     */
    private function inferUnionSemantics(array $schemas): UnionSemantics
    {
        // Default to anyOf unless we can prove branches are disjoint by JSON type.
        $branchTypeSets = [];
        foreach ($schemas as $schema) {
            $jsonTypes = $this->extractJsonTypes($schema);
            if ($jsonTypes === null) {
                return UnionSemantics::AnyOf;
            }

            $branchTypeSets[] = $jsonTypes;
        }

        if ($this->jsonTypeSetsOverlap($branchTypeSets)) {
            return UnionSemantics::AnyOf;
        }

        return UnionSemantics::OneOf;
    }

    /**
     * @return list<string>|null
     */
    private function extractJsonTypes(JsonSchema $schema): ?array
    {
        $serialized = $schema->jsonSerialize();
        if (!\is_array($serialized)) {
            return null;
        }

        $typeValue = $serialized['type'] ?? null;
        if (\is_string($typeValue)) {
            return [$typeValue];
        }

        if (!\is_array($typeValue) || $typeValue === [] || !array_is_list($typeValue)) {
            return null;
        }

        foreach ($typeValue as $type) {
            if (!\is_string($type)) {
                return null;
            }
        }

        /** @var list<string> $typeValue */
        return $typeValue;
    }

    /**
     * @param list<list<string>> $branchTypeSets
     */
    private function jsonTypeSetsOverlap(array $branchTypeSets): bool
    {
        for ($i = 0; $i < \count($branchTypeSets); $i++) {
            for ($j = $i + 1; $j < \count($branchTypeSets); $j++) {
                $a = $branchTypeSets[$i];
                $b = $branchTypeSets[$j];

                // integer is a subset of number in JSON Schema.
                if ((\in_array('integer', $a, true) && \in_array('number', $b, true))
                    || (\in_array('number', $a, true) && \in_array('integer', $b, true))) {
                    return true;
                }

                if (array_intersect($a, $b) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mapIntersectionType(IntersectionType $type): JsonSchema
    {
        $schemas = [];

        foreach ($type->types as $subType) {
            $schema = $this->mapType($subType);

            // Fingerprint to avoid duplicates
            $fingerprint = json_encode($schema->jsonSerialize());
            $schemas[$fingerprint] = $schema;
        }

        $finalSchemas = array_values($schemas);

        if (\count($finalSchemas) === 1) {
            return $finalSchemas[0];
        }

        return (new JsonSchema())->setAllOf($finalSchemas);
    }

    /**
     * @param array<JsonSchema> $schemas
     * @return array<SchemaType>|null
     */
    private function collapseToTypeArrayIfPossible(array $schemas): ?array
    {
        $types = [];

        foreach ($schemas as $schema) {
            $serialized = $schema->jsonSerialize();
            if (!\is_array($serialized)) {
                return null;
            }

            if (array_keys($serialized) !== ['type']) {
                return null;
            }

            $jsonTypes = $this->extractJsonTypes($schema);
            if ($jsonTypes === null) {
                return null;
            }

            foreach ($jsonTypes as $jsonType) {
                $schemaType = SchemaType::tryFrom($jsonType);
                if ($schemaType === null) {
                    return null;
                }

                $types[$schemaType->value] = $schemaType;
            }
        }

        // Avoid redundant/ambiguous unions where integer is already covered by number.
        if (isset($types[SchemaType::NUMBER->value], $types[SchemaType::INTEGER->value])) {
            unset($types[SchemaType::INTEGER->value]);
        }

        if ($types === []) {
            return null;
        }

        return array_values($types);
    }

    private function applyTypeConstraints(JsonSchema $schema, TypeConstraints $constraints): void
    {
        if ($constraints->enum !== []) {
            $schema->setEnum($constraints->enum);
        }

        if ($constraints->minimum !== null) {
            $schema->setMinimum($constraints->minimum);
        }
        if ($constraints->maximum !== null) {
            $schema->setMaximum($constraints->maximum);
        }
        if ($constraints->exclusiveMinimum !== null) {
            $schema->setExclusiveMinimum($constraints->exclusiveMinimum);
        }
        if ($constraints->exclusiveMaximum !== null) {
            $schema->setExclusiveMaximum($constraints->exclusiveMaximum);
        }
        if ($constraints->multipleOf !== null) {
            $schema->setMultipleOf($constraints->multipleOf);
        }

        if ($constraints->minLength !== null) {
            $schema->setMinLength($constraints->minLength);
        }
        if ($constraints->maxLength !== null) {
            $schema->setMaxLength($constraints->maxLength);
        }
        if ($constraints->pattern !== null) {
            $schema->setPattern($constraints->pattern);
        }

        if ($constraints->minItems !== null) {
            $schema->setMinItems($constraints->minItems);
        }
        if ($constraints->maxItems !== null) {
            $schema->setMaxItems($constraints->maxItems);
        }
    }

    private function applyTypeAnnotations(JsonSchema $schema, TypeAnnotations $annotations): void
    {
        if ($annotations->title !== null) {
            $schema->setTitle($annotations->title);
        }

        if ($annotations->description !== null) {
            $schema->setDescription($annotations->description);
        }

        if ($annotations->format !== null) {
            $schema->setFormat($annotations->format);
        }

        if ($annotations->deprecated) {
            $schema->setDeprecated(true);
        }

        if ($annotations->examples !== []) {
            $schema->setExamples($annotations->examples);
        }

        if ($annotations->default !== null && $this->canApplyDefault($schema, $annotations->default)) {
            $schema->setDefault($annotations->default);
        }
    }

    /**
     * Avoids emitting defaults that are known to be incompatible with the generated schema.
     * TODO: Extend this guard to the other JSON Schema primitive types.
     */
    private function canApplyDefault(JsonSchema $schema, mixed $default): bool
    {
        $serialized = $schema->jsonSerialize();
        if (!\is_array($serialized)) {
            return true;
        }

        if (($serialized['type'] ?? null) !== SchemaType::OBJECT->value) {
            return true;
        }

        return \is_object($default);
    }
}
