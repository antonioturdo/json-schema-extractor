<?php

namespace Zeusi\JsonSchemaExtractor\Enricher;

use phpDocumentor\Reflection\DocBlock\Tags\BaseTag;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Types\ContextFactory;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor\PhpDocumentorTypeMapperInterface;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor\PhpDocumentorTypeMapperV5;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor\PhpDocumentorTypeMapperV6;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;

/**
 * Enriches properties directly using pure phpdocumentor/reflection-docblock.
 */
class PhpDocumentorEnricher implements EnricherInterface
{
    private DocBlockFactoryInterface $factory;
    private PhpDocumentorTypeMapperInterface $typeMapper;

    public function __construct(?PhpDocumentorTypeMapperInterface $typeMapper = null)
    {
        $this->factory = DocBlockFactory::createInstance();
        $this->typeMapper = $typeMapper ?? $this->createTypeMapper();
    }

    public function enrich(ClassDefinition $definition, ExtractionContext $context, EnrichmentRuntime $runtime): void
    {
        /** @var class-string $className */
        $className = $definition->getClassName();
        if (!class_exists($className)) {
            return;
        }

        $reflectionClass = new \ReflectionClass($className);

        $contextFactory = new ContextFactory();
        $context = $contextFactory->createFromReflector($reflectionClass);

        // 1. Enrich Class metadata
        $classDocComment = $reflectionClass->getDocComment();
        if ($classDocComment !== false && $classDocComment !== '') {
            $classDocBlock = $this->factory->create($classDocComment, $context);
            $classSummary = trim($classDocBlock->getSummary());
            $classDescription = trim($classDocBlock->getDescription()->render());

            if ($classSummary !== '') {
                $definition->setTitle($classSummary);
            }
            if ($classDescription !== '') {
                $definition->setDescription($classDescription);
            }
        }

        $this->enrichPromotedPropertiesFromConstructorParams($definition, $reflectionClass, $context, $runtime);

        foreach ($definition->getProperties() as $propertyName => $propertyDef) {
            try {
                if (!$reflectionClass->hasProperty($propertyName)) {
                    continue;
                }

                $reflectionProperty = $reflectionClass->getProperty($propertyName);
                $docComment = $reflectionProperty->getDocComment();

                if ($docComment === false || $docComment === '') {
                    continue;
                }

                $docBlock = $this->factory->create($docComment, $context);

                // Title and description
                $title = trim($docBlock->getSummary());
                $description = trim($docBlock->getDescription()->render());

                // Check @deprecated
                if ($docBlock->hasTag('deprecated')) {
                    $runtime->fieldDefinitionUpdater->applyDeprecated($propertyDef);
                }

                // Check @example
                $exampleTags = $docBlock->getTagsByName('example');
                if (!empty($exampleTags)) {
                    $examples = [];
                    foreach ($exampleTags as $exampleTag) {
                        /** @var BaseTag $exampleTag */
                        $example = trim($exampleTag->getDescription()?->render() ?? '');
                        if ($example !== '') {
                            $examples[] = $example;
                        }
                    }

                    if ($examples !== []) {
                        $runtime->fieldDefinitionUpdater->applyExamples($propertyDef, $examples);
                    }
                }

                // Override internal types with precise PHPDoc types parsing
                $varTags = $docBlock->getTagsByName('var');
                if (!empty($varTags)) {
                    $type = null;
                    foreach ($varTags as $varTag) {
                        /** @var Var_ $varTag */
                        $phpDocumentorType = $varTag->getType();

                        if ($title === '' && $varTag->getDescription() !== null) {
                            $title = trim($varTag->getDescription()->render());
                        }

                        if ($phpDocumentorType !== null) {
                            $parsed = $this->typeMapper->parse($phpDocumentorType, $reflectionClass);
                            $type = $type === null ? $parsed : new UnionType([$type, $parsed]);
                        }
                    }

                    if ($type !== null) {
                        $runtime->fieldDefinitionUpdater->applyCompatibleDeclaredType($propertyDef, $type);
                    }
                }
                if ($title !== '') {
                    $runtime->fieldDefinitionUpdater->applyTitle($propertyDef, $title);
                }
                if ($description !== '') {
                    $runtime->fieldDefinitionUpdater->applyDescription($propertyDef, $description);
                }
            } catch (\Exception $e) {
                // Gracefully ignore docblocks that are malformed
            }
        }
    }

    /**
     * @param \ReflectionClass<object> $reflectionClass
     */
    private function enrichPromotedPropertiesFromConstructorParams(ClassDefinition $definition, \ReflectionClass $reflectionClass, \phpDocumentor\Reflection\Types\Context $context, EnrichmentRuntime $runtime): void
    {
        $constructor = $reflectionClass->getConstructor();
        if ($constructor === null) {
            return;
        }

        $constructorDocComment = $constructor->getDocComment();
        if ($constructorDocComment === false || $constructorDocComment === '') {
            return;
        }

        $promotedParameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isPromoted()) {
                $promotedParameters[$parameter->getName()] = true;
            }
        }

        if ($promotedParameters === []) {
            return;
        }

        $docBlock = $this->factory->create($constructorDocComment, $context);
        foreach ($docBlock->getTagsByName('param') as $paramTag) {
            if (!$paramTag instanceof Param) {
                continue;
            }

            $parameterName = $paramTag->getVariableName();
            if ($parameterName === null) {
                continue;
            }

            $parameterName = ltrim($parameterName, '$');
            if (!isset($promotedParameters[$parameterName])) {
                continue;
            }

            $property = $definition->getProperty($parameterName);
            $phpDocumentorType = $paramTag->getType();
            if ($property === null || $phpDocumentorType === null) {
                continue;
            }

            $runtime->fieldDefinitionUpdater->applyCompatibleDeclaredType(
                $property,
                $this->typeMapper->parse($phpDocumentorType, $reflectionClass)
            );
        }
    }

    private function createTypeMapper(): PhpDocumentorTypeMapperInterface
    {
        if (class_exists('phpDocumentor\\Reflection\\PseudoTypes\\Generic')) {
            return new PhpDocumentorTypeMapperV6();
        }

        return new PhpDocumentorTypeMapperV5();
    }
}
