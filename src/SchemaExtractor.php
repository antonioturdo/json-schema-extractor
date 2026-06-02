<?php

namespace Zeusi\JsonSchemaExtractor;

use Zeusi\JsonSchemaExtractor\Attribute\AdditionalProperties;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\ProcessingStackContext;
use Zeusi\JsonSchemaExtractor\Discoverer\DiscovererInterface;
use Zeusi\JsonSchemaExtractor\Enricher\EnricherInterface;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Mapper\JsonSchemaMapperInterface;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\JsonSchemaInterface;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedReferenceType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Serialization\SerializationStrategyInterface;

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

        $schema = $this->extractSubSchema($className, $context, new ProcessingStackContext());
        return $schema->jsonSerialize();
    }

    /**
     * Extracts a JSON Schema object while tracking the recursion stack to avoid infinite loops.
     *
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function extractSubSchema(string $className, ExtractionContext $context, ProcessingStackContext $stack): JsonSchemaInterface
    {
        if ($stack->has($className)) {
            $serializedDefinition = $this->createRecursionReferencePayload($className);
        } else {
            $stack = $stack->pushed($className);
            $serializedDefinition = $this->projectClass($className, $context);
        }

        // 4. Map (passing an anonymous function to allow the Mapper to request projected nested payloads)
        return $this->mapper->map(
            $serializedDefinition,
            function (string $class) use ($context, $stack): SerializedPayloadDefinition {
                /** @var class-string $class */
                return $this->projectSubSchema($class, $context, $stack);
            }
        );
    }

    /**
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function projectSubSchema(string $className, ExtractionContext $context, ProcessingStackContext $stack): SerializedPayloadDefinition
    {
        // Recursion break: if class is already in stack, emit a $ref
        if ($stack->has($className)) {
            return $this->createRecursionReferencePayload($className);
        }

        return $this->projectClass($className, $context);
    }

    /**
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function projectClass(string $className, ExtractionContext $context): SerializedPayloadDefinition
    {
        // 1. Discover
        $definition = $this->discoverer->discover($className);

        // 2. Enrich
        $runtime = new EnrichmentRuntime();
        foreach ($this->enrichers as $enricher) {
            $enricher->enrich($definition, $context, $runtime);
        }

        // 3. Project serialized shape
        $serializedDefinition = $this->serializationStrategy->project($definition, $context);
        return $this->applyAdditionalPropertiesDefault($serializedDefinition, $className);
    }

    /**
     * @param class-string $className
     */
    private function createRecursionReferencePayload(string $className): SerializedPayloadDefinition
    {
        return new SerializedPayloadDefinition(new SerializedReferenceType($className));
    }

    /**
     * @param class-string $className
     * @throws \ReflectionException
     */
    private function applyAdditionalPropertiesDefault(SerializedPayloadDefinition $definition, string $className): SerializedPayloadDefinition
    {
        return new SerializedPayloadDefinition(
            $this->applyAdditionalPropertiesDefaultToType($definition->type, $className)
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
