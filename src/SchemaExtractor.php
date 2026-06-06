<?php

namespace Zeusi\JsonSchemaExtractor;

use Zeusi\JsonSchemaExtractor\Attribute\AdditionalProperties;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Discoverer\DiscovererInterface;
use Zeusi\JsonSchemaExtractor\Enricher\EnricherInterface;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Mapper\JsonSchemaMapperInterface;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedProjection;
use Zeusi\JsonSchemaExtractor\Model\Serialized\ViewId;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedViewReferenceType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Serialization\SerializationStrategyInterface;
use Zeusi\JsonSchemaExtractor\Serialization\State\NeutralProjectionState;
use Zeusi\JsonSchemaExtractor\Serialization\State\ProjectionState;

/**
 * Entry point for extracting JSON Schema documents from PHP classes.
 *
 * The extractor coordinates the full pipeline:
 * - discover the class shape through a {@see DiscovererInterface}
 * - enrich the discovered definition with metadata from optional {@see EnricherInterface} instances
 * - project the enriched definition to its serialized shape through a {@see SerializationStrategyInterface}
 * - map the projected definition to a JSON Schema representation through a {@see JsonSchemaMapperInterface}
 */
class SchemaExtractor
{
    /**
     * @param iterable<EnricherInterface> $enrichers
     */
    public function __construct(
        private readonly DiscovererInterface            $discoverer,
        private readonly iterable                       $enrichers,
        private readonly SerializationStrategyInterface $serializationStrategy,
        private readonly JsonSchemaMapperInterface      $mapper,
        private readonly SchemaExtractorOptions         $options = new SchemaExtractorOptions(),
    ) {}

    /**
     * Extracts a JSON Schema structure for the given class.
     *
     * @param class-string $className
     * @return array<string, mixed>|object
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    public function extract(string $className, ?ExtractionContext $context = null): array|object
    {
        $context ??= new ExtractionContext();

        // Project every reachable class once into a resolved set of views...
        $rootState = $this->serializationStrategy->initialState($context);
        $views = [];
        $this->resolve($className, $context, $rootState, $views, []);

        // ...then map the finished projection to JSON Schema.
        $projection = new SerializedProjection(new ViewId($className, $rootState->viewKey()), $views);
        $schema = $this->mapper->map($projection);
        return $schema->jsonSerialize();
    }

    /**
     * Recursively projects $className and every class it references into $views.
     *
     * Owns recursion, the cycle stack, and deduplication: a class already resolved
     * or currently on the stack is not projected again, which breaks reference cycles.
     *
     * @param class-string $className
     * @param array<string, SerializedPayloadDefinition> $views
     * @param list<string> $stack View keys currently on the resolution path
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function resolve(string $className, ExtractionContext $context, ProjectionState $state, array &$views, array $stack): void
    {
        $key = (new ViewId($className, $state->viewKey()))->key();
        if (isset($views[$key]) || \in_array($key, $stack, true)) {
            return;
        }

        $payload = $this->projectClass($className, $context, $state);

        $childStack = [...$stack, $key];
        foreach ($this->referencedViews($payload->type) as [$referencedClass, $referencedState]) {
            // Interfaces have no concrete shape to project; the mapper raises a
            // dedicated error when it folds them.
            if (interface_exists($referencedClass)) {
                continue;
            }

            $this->resolve($referencedClass, $context, $referencedState, $views, $childStack);
        }

        $views[$key] = $payload;
    }

    /**
     * Collects the class-backed views referenced inside a serialized type, recursing into
     * containers, unions, and inline object shapes. Each reference carries the state under
     * which the referenced class should be projected.
     *
     * @return list<array{0: class-string, 1: ProjectionState}>
     */
    private function referencedViews(Type $type): array
    {
        $views = [];
        $this->collectReferencedViews($type, $views);

        return array_values($views);
    }

    /**
     * @param array<string, array{0: class-string, 1: ProjectionState}> $views
     */
    private function collectReferencedViews(Type $type, array &$views): void
    {
        if ($type instanceof SerializedViewReferenceType) {
            $views[(new ViewId($type->className, $type->childState->viewKey()))->key()] = [$type->className, $type->childState];
            return;
        }

        if ($type instanceof ClassLikeType) {
            // A bare class reference is the canonical view.
            $views[(new ViewId($type->name))->key()] = [$type->name, NeutralProjectionState::instance()];
            return;
        }

        if ($type instanceof DecoratedType || $type instanceof ArrayType || $type instanceof MapType) {
            $this->collectReferencedViews($type->type, $views);
            return;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $subType) {
                $this->collectReferencedViews($subType, $views);
            }
            return;
        }

        if ($type instanceof SerializedObjectType) {
            foreach ($type->shape->concreteClasses as $concreteClass) {
                // Discriminator subtypes are always referenced in their canonical view.
                $views[(new ViewId($concreteClass))->key()] = [$concreteClass, NeutralProjectionState::instance()];
            }

            foreach ($type->shape->properties as $property) {
                if ($property->type !== null) {
                    $this->collectReferencedViews($property->type, $views);
                }
            }
        }
    }

    /**
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function projectClass(string $className, ExtractionContext $context, ProjectionState $state): SerializedPayloadDefinition
    {
        // 1. Discover
        $definition = $this->discoverer->discover($className);

        // 2. Enrich
        $runtime = new EnrichmentRuntime();
        foreach ($this->enrichers as $enricher) {
            $enricher->enrich($definition, $context, $runtime);
        }

        // 3. Project serialized shape
        $serializedDefinition = $this->serializationStrategy->project($definition, $context, $state);
        return $this->applyAdditionalPropertiesDefault($serializedDefinition, $className);
    }

    /**
     * @param class-string $className
     * @throws \ReflectionException
     */
    private function applyAdditionalPropertiesDefault(SerializedPayloadDefinition $definition, string $className): SerializedPayloadDefinition
    {
        return new SerializedPayloadDefinition(
            $this->applyAdditionalPropertiesDefaultToType($definition->type, $className),
            $definition->inlineOnly,
        );
    }

    /**
     * @param class-string $className
     * @throws \ReflectionException
     */
    private function applyAdditionalPropertiesDefaultToType(Type $type, string $className): Type
    {
        if ($type instanceof DecoratedType) {
            return new DecoratedType(
                $this->applyAdditionalPropertiesDefaultToType($type->type, $className),
                $type->constraints,
                $type->annotations
            );
        }

        if (!$type instanceof SerializedObjectType) {
            return $type;
        }

        return new SerializedObjectType($this->applyAdditionalPropertiesDefaultToObject(
            $type->shape,
            $className
        ));
    }

    /**
     * @param class-string $className
     * @throws \ReflectionException
     */
    private function applyAdditionalPropertiesDefaultToObject(SerializedObjectDefinition $definition, string $className): SerializedObjectDefinition
    {
        if ($definition->additionalProperties !== null) {
            return $definition;
        }

        return new SerializedObjectDefinition(
            name: $definition->name,
            properties: $definition->properties,
            title: $definition->title,
            description: $definition->description,
            additionalProperties: $this->getAdditionalPropertiesSetting($className) ?? $this->options->defaultAdditionalProperties,
            concreteClasses: $definition->concreteClasses
        );
    }

    /**
     * @param class-string $className
     * @throws \ReflectionException
     */
    private function getAdditionalPropertiesSetting(string $className): ?bool
    {
        $reflectionClass = new \ReflectionClass($className);
        $attributes = $reflectionClass->getAttributes(AdditionalProperties::class);
        if ($attributes === []) {
            return null;
        }

        /** @var AdditionalProperties $attribute */
        $attribute = $attributes[0]->newInstance();
        return $attribute->enabled;
    }
}
