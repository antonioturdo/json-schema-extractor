<?php

namespace Zeusi\JsonSchemaGenerator;

use Zeusi\JsonSchemaGenerator\Attribute\AdditionalProperties;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Context\ProcessingStackContext;
use Zeusi\JsonSchemaGenerator\Discoverer\PropertyDiscovererInterface;
use Zeusi\JsonSchemaGenerator\Enricher\PropertyEnricherInterface;
use Zeusi\JsonSchemaGenerator\JsonSchema\Schema;
use Zeusi\JsonSchemaGenerator\Mapper\SchemaMapperInterface;

/**
 * The Coordinator of our architectural JSON Schema library.
 * Coordinates the pipeline: Discovery -> Enrichment (n-times) -> Mapping.
 */
class SchemaGenerator
{
    /**
     * @param iterable<PropertyEnricherInterface> $enrichers
     */
    public function __construct(
        private readonly PropertyDiscovererInterface $discoverer,
        private readonly iterable $enrichers,
        private readonly SchemaMapperInterface $mapper,
        private readonly SchemaGeneratorOptions $options = new SchemaGeneratorOptions(),
    ) {}

    /**
     * Generates a Draft-7 JSON Schema structure for the given class.
     *
     * @param class-string $className
     * @return array<string, mixed>|object
     *
     * @throws \LogicException
     */
    public function generate(string $className, ?GenerationContext $context = null): array|object
    {
        $context ??= new GenerationContext();

        if (null === $context->find(ProcessingStackContext::class)) {
            $context = $context->with(new ProcessingStackContext());
        }

        $schema = $this->generateSubSchema($className, $context);
        return $schema->jsonSerialize();
    }

    /**
     * Generates the Schema object, tracking the recursion stack to avoid infinite loops.
     *
     * @param class-string $className
     *
     * @throws \LogicException
     * @throws \ReflectionException
     */
    private function generateSubSchema(string $className, GenerationContext $context): Schema
    {
        $processingStack = $this->getProcessingStack($context);
        // Recursion break: if class is already in stack, emit a $ref
        if ($processingStack->has($className)) {
            return (new Schema())->setRef("#/components/schemas/" . str_replace('\\', '.', $className));
        }

        $processingStack->pushed($className);

        // 1. Discover
        $definition = $this->discoverer->discover($className);

        $definition->additionalProperties = $this->getAdditionalPropertiesSetting($className) ?? $this->options->defaultAdditionalProperties;

        // 2. Enrich
        foreach ($this->enrichers as $enricher) {
            $enricher->enrich($definition, $context);
        }

        // 3. Map (Passing an anonymous function to allow the Mapper to recursively request schemas)
        return $this->mapper->map(
            $definition,
            function (string $class) use ($context): Schema {
                /** @var class-string $class */
                return $this->generateSubSchema($class, $context);
            }
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
    private function getProcessingStack(GenerationContext $context): ProcessingStackContext
    {
        return $context->find(ProcessingStackContext::class) ?? throw new \LogicException('ProcessingStackContext not found in GenerationContext');
    }
}
