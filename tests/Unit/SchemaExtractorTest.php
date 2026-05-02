<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Mapper\ClassReferenceStrategy;
use Zeusi\JsonSchemaExtractor\Mapper\SchemaMapperInterface;
use Zeusi\JsonSchemaExtractor\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Schema;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\SchemaExtractorOptions;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\AdditionalPropertiesObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\BasicObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CircularObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\ComplexDiscriminatorContainer;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\DiscriminatorAnimal;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\DiscriminatorCat;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\PhpDocObject;

#[CoversClass(SchemaExtractor::class)]
#[CoversClass(StandardSchemaMapper::class)]
class SchemaExtractorTest extends TestCase
{
    /**
     * @throws \ReflectionException
     */
    public function testGenerateSetsAdditionalPropertiesFalseByDefaultForClassSchemas(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [],
            new JsonEncodeSerializationStrategy(),
            new StandardSchemaMapper()
        );

        $schema = $extractor->extract(BasicObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertFalse($schema['additionalProperties']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateAllowsAdditionalPropertiesOverrideViaAttribute(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [],
            new JsonEncodeSerializationStrategy(),
            new StandardSchemaMapper(),
        );

        $schema = $extractor->extract(AdditionalPropertiesObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertTrue($schema['additionalProperties']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateAllowsAdditionalPropertiesDefaultViaOptions(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [],
            new JsonEncodeSerializationStrategy(),
            new StandardSchemaMapper(),
            new SchemaExtractorOptions(defaultAdditionalProperties: true)
        );

        $schema = $extractor->extract(BasicObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertTrue($schema['additionalProperties']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateMapsArrayStringToStringAsDictionary(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [new PhpDocumentorEnricher()],
            new JsonEncodeSerializationStrategy(),
            new StandardSchemaMapper()
        );

        $schema = $extractor->extract(PhpDocObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertSame('object', $schema['properties']['headers']['type']);
        self::assertArrayNotHasKey('default', $schema['properties']['headers']);
        self::assertSame('string', $schema['properties']['headers']['additionalProperties']['type']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateCanPlaceClassBackedSchemasInDefinitions(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [new PhpStanEnricher()],
            new JsonEncodeSerializationStrategy(),
            new StandardSchemaMapper(ClassReferenceStrategy::Definitions)
        );

        $schema = $extractor->extract(PhpDocObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertSame('#/definitions/BasicObject', $schema['properties']['objects']['items']['$ref']);
        self::assertSame('object', $schema['definitions']['BasicObject']['type']);
        self::assertSame('string', $schema['definitions']['StatusEnum']['type']);
        self::assertSame(['active', 'inactive'], $schema['definitions']['StatusEnum']['enum']);
        self::assertSame('#/definitions/StatusEnum', $schema['properties']['mixedTags']['items']['anyOf'][0]['$ref']);
        self::assertSame('object', $schema['properties']['settings']['type']);
        self::assertArrayNotHasKey('$ref', $schema['properties']['settings']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateMapsSymfonySerializerDiscriminatorBaseClassToOneOf(): void
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializationStrategy = new SymfonySerializerStrategy($metadataFactory);

        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [],
            $serializationStrategy,
            new StandardSchemaMapper(),
        );

        $context = (new ExtractionContext())->with(new SymfonySerializerContext());

        $schema = $extractor->extract(DiscriminatorAnimal::class, $context);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertArrayHasKey('oneOf', $schema);
        self::assertCount(2, $schema['oneOf']);

        $enums = [];
        foreach ($schema['oneOf'] as $subSchema) {
            $definitionName = str_replace('#/definitions/', '', $subSchema['$ref']);
            $enums[] = $schema['definitions'][$definitionName]['properties']['type']['enum'][0] ?? null;
        }
        sort($enums);

        self::assertSame(['cat', 'dog'], $enums);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateMapsNestedDiscriminatedTypesThroughSchemaProvider(): void
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializationStrategy = new SymfonySerializerStrategy($metadataFactory);

        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [new PhpDocumentorEnricher()],
            $serializationStrategy,
            new StandardSchemaMapper(),
        );

        $context = (new ExtractionContext())->with(new SymfonySerializerContext());

        $schema = $extractor->extract(ComplexDiscriminatorContainer::class, $context);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertSame('#/definitions/DiscriminatorAnimal', $schema['properties']['primaryAnimal']['$ref']);
        self::assertArrayHasKey('oneOf', $schema['definitions']['DiscriminatorAnimal']);
        self::assertCount(2, $schema['definitions']['DiscriminatorAnimal']['oneOf']);

        self::assertArrayHasKey('anyOf', $schema['properties']['optionalAnimal']);
        self::assertSame('#/definitions/DiscriminatorAnimal', $schema['properties']['optionalAnimal']['anyOf'][0]['$ref']);
        self::assertSame('null', $schema['properties']['optionalAnimal']['anyOf'][1]['type']);

        self::assertSame('array', $schema['properties']['animals']['type']);
        self::assertSame('#/definitions/DiscriminatorAnimal', $schema['properties']['animals']['items']['$ref']);

        self::assertSame('object', $schema['properties']['animalsByName']['type']);
        self::assertSame('#/definitions/DiscriminatorAnimal', $schema['properties']['animalsByName']['additionalProperties']['$ref']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateDoesNotFailWhenVirtualDiscriminatorPropertyIsPresent(): void
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializationStrategy = new SymfonySerializerStrategy($metadataFactory);

        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [new PhpStanEnricher()],
            $serializationStrategy,
            new StandardSchemaMapper(),
        );

        $context = (new ExtractionContext())->with(new SymfonySerializerContext());

        $schema = $extractor->extract(DiscriminatorCat::class, $context);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertArrayHasKey('type', $schema['properties']);
        self::assertSame(['cat'], $schema['properties']['type']['enum']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateMapsDateTimeInterfaceAsJsonEncodeObjectShape(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [],
            new JsonEncodeSerializationStrategy(),
            new StandardSchemaMapper()
        );

        $schema = $extractor->extract(BasicObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertSame('object', $schema['type']);
        self::assertSame('object', $schema['properties']['createdAt']['type']);
        self::assertSame('string', $schema['properties']['createdAt']['properties']['date']['type']);
        self::assertSame('^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$', $schema['properties']['createdAt']['properties']['date']['pattern']);
        self::assertSame('integer', $schema['properties']['createdAt']['properties']['timezone_type']['type']);
        self::assertSame([1, 2, 3], $schema['properties']['createdAt']['properties']['timezone_type']['enum']);
        self::assertSame('string', $schema['properties']['createdAt']['properties']['timezone']['type']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateCollapsesPrimitiveUnionToTypeArray(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [],
            new JsonEncodeSerializationStrategy(),
            new StandardSchemaMapper()
        );

        $schema = $extractor->extract(BasicObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertIsArray($schema['properties']['union']['type']);
        self::assertSame(['string', 'integer', 'null'], $schema['properties']['union']['type']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testGenerateBreaksCircularReferencesWhenClassSchemasAreInlined(): void
    {
        $mapper = new RecursionProbeMapper(CircularObject::class);
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [],
            new JsonEncodeSerializationStrategy(),
            $mapper
        );

        $schema = $extractor->extract(CircularObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertSame(
            '#/components/schemas/Zeusi.JsonSchemaExtractor.Tests.Fixtures.CircularObject',
            $schema['properties']['child']['$ref']
        );
        self::assertSame(1, $mapper->mapCalls);
    }
}

final class RecursionProbeMapper implements SchemaMapperInterface
{
    public int $mapCalls = 0;

    /**
     * @param class-string $recursiveClass
     */
    public function __construct(
        private readonly string $recursiveClass
    ) {}

    public function map(SerializedObjectDefinition $definition, callable $schemaProvider): Schema
    {
        ++$this->mapCalls;

        if ($this->mapCalls > 1) {
            return (new Schema())->setTitle('recursion guard failed');
        }

        return (new Schema())->addProperty('child', $schemaProvider($this->recursiveClass));
    }
}
