<?php

namespace Zeusi\JsonSchemaExtractor;

use Zeusi\JsonSchemaExtractor\Attribute\AdditionalProperties;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\ProcessingStackContext;
use Zeusi\JsonSchemaExtractor\Discoverer\DiscovererInterface;
use Zeusi\JsonSchemaExtractor\Enricher\EnricherInterface;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Mapper\SchemaMapperInterface;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Schema;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Serialization\SerializationStrategyInterface;

/**
 * Entry point for extracting JSON Schema documents from PHP classes.
 *
 * The extractor coordinates the full pipeline:
 * - discover the class shape through a {@see DiscovererInterface}
 * - enrich the discovered definition with metadata from optional {@see EnricherInterface} instances
 * - project the enriched definition to its serialized shape through a {@see SerializationStrategyInterface}
 * - map the projected definition to a JSON Schema representation through a {@see SchemaMapperInterface}
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
        private readonly SchemaMapperInterface          $mapper,
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

        if (null === $context->find(ProcessingStackContext::class)) {
            $context = $context->with(new ProcessingStackContext());
        }

        $schema = $this->extractSubSchema($className, $context);
        return $schema->jsonSerialize();
    }

    /**
     * Extracts a Schema object while tracking the recursion stack to avoid infinite loops.
     *
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function extractSubSchema(string $className, ExtractionContext $context): Schema
    {
        $processingStack = $this->getProcessingStack($context);
        // Recursion break: if class is already in stack, emit a $ref
        if ($processingStack->has($className)) {
            return (new Schema())->setRef("#/components/schemas/" . str_replace('\\', '.', $className));
        }

        $context = $context->with($processingStack->pushed($className));

        // 1. Discover
        $definition = $this->discoverer->discover($className);

        // 2. Enrich
        $runtime = new EnrichmentRuntime();
        foreach ($this->enrichers as $enricher) {
            $enricher->enrich($definition, $context, $runtime);
        }

        // 3. Project serialized shape
        $serializedDefinition = $this->serializationStrategy->project($definition, $context);
        $serializedDefinition = $this->applyAdditionalPropertiesDefault($serializedDefinition, $className);

        // 4. Map (Passing an anonymous function to allow the Mapper to recursively request schemas)
        return $this->mapper->map(
            $serializedDefinition,
            function (string $class) use ($context): Schema {
                /** @var class-string $class */
                return $this->extractSubSchema($class, $context);
            }
        );
    }

    /**
     * @param class-string $className
     * @throws \ReflectionException
     */
    private function applyAdditionalPropertiesDefault(SerializedObjectDefinition $definition, string $className): SerializedObjectDefinition
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

    /**
     * @throws \LogicException
     */
    private function getProcessingStack(ExtractionContext $context): ProcessingStackContext
    {
        return $context->find(ProcessingStackContext::class) ?? throw new \LogicException('ProcessingStackContext not found in ExtractionContext');
    }
}
