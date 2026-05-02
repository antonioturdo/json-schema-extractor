<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Schema;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\SchemaType;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
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
use Zeusi\JsonSchemaExtractor\Model\Type\UnionSemantics;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;

/**
 * The standard mapper that translates the serialized object definition into a Draft-7 JSON Schema array.
 *
 * It uses the internal Schema object to easily build and compose the output.
 */
class StandardSchemaMapper implements SchemaMapperInterface
{
    /** @var callable(string): Schema */
    private $schemaProvider;

    /** @var array<string, Schema> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $definitionNamesByClass = [];

    /** @var array<string, true> */
    private array $buildingDefinitions = [];

    private int $mapDepth = 0;

    private ?string $rootClassName = null;

    public function __construct(
        private readonly ClassReferenceStrategy $classReferenceStrategy = ClassReferenceStrategy::Definitions
    ) {}

    public function map(SerializedObjectDefinition $definition, callable $schemaProvider): Schema
    {
        $isRootMap = $this->mapDepth === 0;
        if ($isRootMap) {
            $this->resetDefinitionsState($definition->name);
        }

        $this->schemaProvider = $schemaProvider;

        ++$this->mapDepth;
        try {
            $concreteClasses = $definition->concreteClasses;
            if ($concreteClasses !== []) {
                $schema = $this->mapConcreteClasses($concreteClasses);
            } else {
                $schema = $this->mapObjectShape($definition);
            }
        } finally {
            --$this->mapDepth;
        }

        if ($isRootMap) {
            if ($this->classReferenceStrategy === ClassReferenceStrategy::Definitions && $this->definitions !== []) {
                $schema->setDefinitions($this->definitions);
            }

            $this->rootClassName = null;
        }

        return $schema;
    }

    /**
     * @param list<class-string> $concreteClasses
     */
    private function mapConcreteClasses(array $concreteClasses): Schema
    {
        $schemas = [];
        foreach ($concreteClasses as $className) {
            if ($this->classReferenceStrategy === ClassReferenceStrategy::Definitions) {
                $this->registerDefinition($className);
                $schemas[] = (new Schema())->setRef($this->definitionRef($className));
            } else {
                $schemas[] = ($this->schemaProvider)($className);
            }
        }

        return (new Schema())->setOneOf($schemas);
    }

    private function mapObjectShape(SerializedObjectDefinition $definition): Schema
    {
        $schema = (new Schema())
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
                $propertySchema = new Schema();
            }

            $schema->addProperty(
                $property->name,
                $propertySchema,
                $property->required
            );
        }

        return $schema;
    }

    private function mapType(Type $type): Schema
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
            $schema = (new Schema())->setType(SchemaType::ARRAY);
            $schema->setItems($this->mapType($type->type));
            return $schema;
        }

        if ($type instanceof MapType) {
            $valueSchema = $this->mapType($type->type);
            return (new Schema())
                ->setType(SchemaType::OBJECT)
                ->setAdditionalProperties($valueSchema);
        }

        if ($type instanceof InlineObjectType) {
            throw new \LogicException('InlineObjectType must be projected to SerializedObjectType before schema mapping.');
        }

        if ($type instanceof SerializedObjectType) {
            return $this->mapObjectShape($type->shape);
        }

        if ($type instanceof BuiltinType) {
            return $this->mapBuiltinType($type);
        }

        if ($type instanceof UnknownType) {
            return new Schema();
        }

        if ($type instanceof EnumType) {
            return $this->mapEnumType($type);
        }

        if ($type instanceof ClassLikeType) {
            return $this->mapClassLikeType($type);
        }

        return new Schema();
    }

    private function mapBuiltinType(BuiltinType $type): Schema
    {
        // mixed value => empty schema
        if ($type->name === 'mixed') {
            return new Schema();
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

        $schema = (new Schema())->setType($schemaType);

        if ($type->name === 'array' || $type->name === 'iterable') {
            $schema->setItems(new Schema());
        }

        if ($type->name === 'object') {
            $schema->setAdditionalProperties(true);
        }

        return $schema;
    }

    private function mapClassLikeType(ClassLikeType $type): Schema
    {
        $className = $type->name;

        if (interface_exists($className)) {
            throw new \RuntimeException(\sprintf('Cannot generate JSON Schema for interface "%s" automatically: 
                    interfaces do not define properties or a concrete shape. Provide a concrete DTO class, 
                    or add an enricher that maps this interface to one or more concrete implementations (oneOf), 
                    or to an untyped object (additionalProperties: true).', $className));
        }

        if ($this->classReferenceStrategy === ClassReferenceStrategy::Definitions) {
            if ($className === $this->rootClassName) {
                return (new Schema())->setRef('#');
            }

            $this->registerDefinition($className);
            return (new Schema())->setRef($this->definitionRef($className));
        }

        return ($this->schemaProvider)($className);
    }

    private function mapEnumType(EnumType $type): Schema
    {
        if ($this->classReferenceStrategy === ClassReferenceStrategy::Definitions) {
            $this->registerEnumDefinition($type->className);
            return (new Schema())->setRef($this->definitionRef($type->className));
        }

        return $this->buildEnumSchema($type->className);
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    private function buildEnumSchema(string $enumClass): Schema
    {
        $reflectionEnum = new \ReflectionEnum($enumClass);
        $cases = [];
        foreach ($reflectionEnum->getCases() as $case) {
            $cases[] = $case instanceof \ReflectionEnumBackedCase ? $case->getBackingValue() : $case->getName();
        }

        return (new Schema())
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
        $this->definitions[$definitionName] = ($this->schemaProvider)($className);
        unset($this->buildingDefinitions[$className]);
    }

    /**
     * @param class-string $className
     */
    private function definitionRef(string $className): string
    {
        return '#/definitions/' . $this->definitionName($className);
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

    private function mapUnionType(UnionType $type): Schema
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
            return (new Schema())->setTypeUnion($collapsedTypes);
        }

        $semantics = $type->semantics ?? $this->inferUnionSemantics($finalSchemas);
        return match ($semantics) {
            UnionSemantics::OneOf => (new Schema())->setOneOf($finalSchemas),
            UnionSemantics::AnyOf => (new Schema())->setAnyOf($finalSchemas),
        };
    }

    /**
     * @param array<Schema> $schemas
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
    private function extractJsonTypes(Schema $schema): ?array
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

    private function mapIntersectionType(IntersectionType $type): Schema
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

        return (new Schema())->setAllOf($finalSchemas);
    }

    /**
     * @param array<Schema> $schemas
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

    private function applyTypeConstraints(Schema $schema, TypeConstraints $constraints): void
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

    private function applyTypeAnnotations(Schema $schema, TypeAnnotations $annotations): void
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
    private function canApplyDefault(Schema $schema, mixed $default): bool
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
