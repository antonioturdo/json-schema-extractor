<?php

namespace Zeusi\JsonSchemaExtractor\Mapper;

use Zeusi\JsonSchemaExtractor\Model\JsonSchema\JsonSchema;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\SchemaType;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedProjection;
use Zeusi\JsonSchemaExtractor\Model\Serialized\ViewId;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedViewReferenceType;
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
    /** The projection being mapped; set at the start of each map() call. */
    private SerializedProjection $projection;

    /**
     * Reusable definitions collected during the pass, indexed by definition name.
     * @var array<string, JsonSchema>
     */
    private array $definitions = [];

    /**
     * Cache of class name => definition name, to keep names stable and resolve collisions.
     * @var array<string, string>
     */
    private array $definitionNamesByClass = [];

    /**
     * Definitions currently being built, used to break cycles in Definitions mode.
     * @var array<string, true> Keyed by ViewId::key()
     */
    private array $buildingDefinitions = [];

    /**
     * Views currently being inlined on the active branch, used to break cycles in inline expansion.
     * @var array<string, true> Keyed by ViewId::key()
     */
    private array $inliningViews = [];

    /** Class name of the root view; lets a self-reference emit "#". Null when the root is not an object. */
    private ?string $rootClassName = null;

    /** View key of the root view; paired with $rootClassName for the self-reference check. */
    private string $rootViewKey = '';

    public function __construct(
        private readonly StandardJsonSchemaMapperOptions $options = new StandardJsonSchemaMapperOptions()
    ) {}

    /**
     * Folds a resolved projection into a JSON Schema document: maps the root view, then attaches
     * the collected reusable definitions and the dialect keyword.
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    public function map(SerializedProjection $projection): JsonSchema
    {
        $this->projection = $projection;
        $rootPayload = $projection->rootPayload();

        // Initialize the working state for this mapping pass.
        $this->definitions = [];
        $this->definitionNamesByClass = [];
        $this->buildingDefinitions = [];
        $this->rootClassName = $this->extractRootObjectDefinition($rootPayload->type)?->name;
        $this->rootViewKey = $projection->root()->viewKey;
        $this->inliningViews = $this->rootClassName !== null
            ? [(new ViewId($this->rootClassName, $this->rootViewKey))->key() => true]
            : [];

        $schema = $this->mapType($rootPayload->type);

        if ($this->options->includeSchemaKeyword) {
            $schema->setSchema($this->options->dialect->schemaUri());
        }

        // Emits the definitions block when any were collected — including a definition
        // promoted to break a non-root cycle in inline mode (no-op when there are none).
        $this->applyDefinitions($schema);

        return $schema;
    }

    /** Finds the object shape at the root of a payload type (unwrapping decoration); null if the root is not an object. */
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
     * Maps discriminator subtypes to a `oneOf` of their canonical views.
     *
     * @param list<class-string> $concreteClasses
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function mapConcreteClasses(array $concreteClasses): JsonSchema
    {
        $schemas = [];
        foreach ($concreteClasses as $className) {
            // Discriminator subtypes are always referenced in their canonical view.
            if ($this->options->classReferenceStrategy === ClassReferenceStrategy::Definitions) {
                $this->registerDefinition($className);
                $schemas[] = (new JsonSchema())->setRef($this->definitionRef($className));
            } else {
                $schemas[] = $this->provideNestedSchema(new ViewId($className));
            }
        }

        return (new JsonSchema())->setOneOf($schemas);
    }

    /**
     * Maps an object shape to an `object` schema with its title, description, additionalProperties, and properties.
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
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

    /**
     * Maps any serialized type to its JSON Schema, recursing into nested types. Central dispatch of the fold.
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
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

        if ($type instanceof SerializedViewReferenceType) {
            return $this->mapClassReference($type->className, $type->childState->viewKey());
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

    /**
     * Maps a builtin type to its JSON Schema (`mixed` => empty schema; array/iterable and object get sensible defaults).
     *
     * @throws \LogicException
     */
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

    /**
     * Maps a bare class type, i.e. the canonical (un-narrowed) view of that class.
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function mapClassLikeType(ClassLikeType $type): JsonSchema
    {
        return $this->mapClassReference($type->name, '');
    }

    /**
     * Maps a reference to a class-backed view: a referenceable view (set by the strategy) follows the
     * configured reference strategy, while an inline-only view (e.g. an ATTRIBUTES projection) is
     * expanded in place like an anonymous shape.
     *
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function mapClassReference(string $className, string $viewKey): JsonSchema
    {
        if (interface_exists($className)) {
            throw new \RuntimeException(\sprintf('Cannot generate JSON Schema for interface "%s" automatically:
                    interfaces do not define properties or a concrete shape. Provide a concrete DTO class,
                    or add an enricher that maps this interface to one or more concrete implementations (oneOf),
                    or to an untyped object (additionalProperties: true).', $className));
        }

        $viewId = new ViewId($className, $viewKey);

        if (!$this->projection->get($viewId)->inlineOnly
            && $this->options->classReferenceStrategy === ClassReferenceStrategy::Definitions
        ) {
            return $this->referenceTo($className, $viewKey);
        }

        return $this->inlineView($viewId, $className, $viewKey);
    }

    /**
     * Emits a reference to a view: the document root ("#") when it is the root view, otherwise a
     * shared definition (registered on demand).
     *
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function referenceTo(string $className, string $viewKey): JsonSchema
    {
        if ($className === $this->rootClassName && $viewKey === $this->rootViewKey) {
            return (new JsonSchema())->setRef('#');
        }

        $this->registerDefinition($className);
        return (new JsonSchema())->setRef($this->definitionRef($className));
    }

    /**
     * Expands a view in place. A cycle cannot be inlined, so when the view is already being inlined
     * on the current branch it is broken with a reference instead.
     *
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function inlineView(ViewId $viewId, string $className, string $viewKey): JsonSchema
    {
        if (isset($this->inliningViews[$viewId->key()])) {
            return $this->referenceTo($className, $viewKey);
        }

        $this->inliningViews[$viewId->key()] = true;
        try {
            return $this->provideNestedSchema($viewId);
        } finally {
            unset($this->inliningViews[$viewId->key()]);
        }
    }

    /**
     * Maps an enum, either inline or as a referenced definition, per the configured strategy.
     *
     * @throws \ReflectionException
     */
    private function mapEnumType(EnumType $type): JsonSchema
    {
        if ($this->options->classReferenceStrategy === ClassReferenceStrategy::Definitions) {
            $this->registerEnumDefinition($type->className);
            return (new JsonSchema())->setRef($this->definitionRef($type->className));
        }

        return $this->buildEnumSchema($type->className);
    }

    /** Attaches the collected definitions to the root schema under the dialect's keyword; no-op when there are none. */
    private function applyDefinitions(JsonSchema $schema): void
    {
        if ($this->definitions === []) {
            return;
        }

        match ($this->options->dialect) {
            JsonSchemaDialect::Draft7 => $schema->setDefinitions($this->definitions),
            JsonSchemaDialect::Draft202012 => $schema->setDefs($this->definitions),
        };
    }

    /**
     * Looks up a view's payload in the projection and maps it.
     *
     * @throws \ReflectionException
     * @throws \LogicException
     */
    private function provideNestedSchema(ViewId $viewId): JsonSchema
    {
        $payload = $this->projection->get($viewId);
        return $this->mapType($payload->type);
    }

    /**
     * Builds an inline enum schema from the enum's cases (backing values, or names for pure enums).
     *
     * @param class-string<\UnitEnum> $enumClass
     *
     * @throws \ReflectionException
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
     * Registers an enum as a reusable definition (once per name).
     *
     * @param class-string<\UnitEnum> $enumClass
     *
     * @throws \ReflectionException
     */
    private function registerEnumDefinition(string $enumClass): void
    {
        $definitionName = $this->definitionName($enumClass);
        if (isset($this->definitions[$definitionName])) {
            return;
        }

        $this->definitions[$definitionName] = $this->buildEnumSchema($enumClass);
    }

    /**
     * Registers a class's canonical view as a reusable definition, guarding against re-entrant cycles.
     *
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function registerDefinition(string $className): void
    {
        $definitionName = $this->definitionName($className);
        if (isset($this->definitions[$definitionName]) || isset($this->buildingDefinitions[$className])) {
            return;
        }

        $this->buildingDefinitions[$className] = true;
        $this->definitions[$definitionName] = $this->provideNestedSchema(new ViewId($className));
        unset($this->buildingDefinitions[$className]);
    }

    /**
     * Builds the dialect-specific `$ref` pointer (`#/definitions/...` or `#/$defs/...`) to a class definition.
     *
     * @param class-string $className
     *
     * @throws \ReflectionException
     */
    private function definitionRef(string $className): string
    {
        return $this->options->dialect->definitionsRefPrefix() . $this->definitionName($className);
    }

    /**
     * Resolves the stable definition name for a class (short name, falling back to a dotted FQN on collision).
     *
     * @param class-string $className
     *
     * @throws \ReflectionException
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

    /**
     * Maps a union: deduplicates branches, then collapses to a type array, or emits `oneOf`/`anyOf`.
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
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
     * Infers union semantics: `oneOf` only when the branches are provably disjoint by JSON type, else `anyOf`.
     *
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
     * Extracts the declared JSON type(s) of a schema, or null if it is not a plain `type`-only schema.
     *
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
     * Tests whether any two branch type-sets overlap, treating `integer` as a subset of `number`.
     *
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

    /**
     * Maps an intersection to an `allOf` of its (deduplicated) branches.
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
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
     * Collapses type-only branches into a single `type` array (dropping `integer` when `number` is present),
     * or returns null when the branches cannot be reduced this way.
     *
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

    /** Applies validation constraints (enum, numeric bounds, length, pattern, item counts) to a schema. */
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

    /** Applies metadata annotations (title, description, format, deprecated, examples, default) to a schema. */
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
     * @todo: Extend this guard to the other JSON Schema primitive types.
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
