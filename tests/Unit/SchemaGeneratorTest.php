<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Context\SymfonySerializerContext;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\SerializerPropertyEnricher;
use Zeusi\JsonSchemaGenerator\Mapper\ClassReferenceStrategy;
use Zeusi\JsonSchemaGenerator\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaGenerator\SchemaGenerator;
use Zeusi\JsonSchemaGenerator\SchemaGeneratorOptions;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\AdditionalPropertiesObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\BasicObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\ComplexDiscriminatorContainer;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\DiscriminatorAnimal;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\DiscriminatorCat;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\PhpDocObject;

#[CoversClass(SchemaGenerator::class)]
#[CoversClass(StandardSchemaMapper::class)]
class SchemaGeneratorTest extends TestCase
{
    public function testGenerateSetsAdditionalPropertiesFalseByDefaultForClassSchemas(): void
    {
        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [],
            new StandardSchemaMapper()
        );

        $schema = $generator->generate(BasicObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertFalse($schema['additionalProperties']);
    }

    public function testGenerateAllowsAdditionalPropertiesOverrideViaAttribute(): void
    {
        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [],
            new StandardSchemaMapper()
        );

        $schema = $generator->generate(AdditionalPropertiesObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertTrue($schema['additionalProperties']);
    }

    public function testGenerateAllowsAdditionalPropertiesDefaultViaOptions(): void
    {
        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [],
            new StandardSchemaMapper(),
            new SchemaGeneratorOptions(defaultAdditionalProperties: true)
        );

        $schema = $generator->generate(BasicObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertTrue($schema['additionalProperties']);
    }

    public function testGenerateMapsArrayStringToStringAsDictionary(): void
    {
        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [new PhpDocumentorEnricher()],
            new StandardSchemaMapper()
        );

        $schema = $generator->generate(PhpDocObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertSame('object', $schema['properties']['headers']['type']);
        self::assertArrayNotHasKey('default', $schema['properties']['headers']);
        self::assertSame('string', $schema['properties']['headers']['additionalProperties']['type']);
    }

    public function testGenerateCanPlaceClassBackedSchemasInDefinitions(): void
    {
        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [new PhpStanEnricher()],
            new StandardSchemaMapper(ClassReferenceStrategy::Definitions)
        );

        $schema = $generator->generate(PhpDocObject::class);
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

    public function testGenerateMapsSymfonySerializerDiscriminatorBaseClassToOneOf(): void
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $enricher = new SerializerPropertyEnricher($metadataFactory);

        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [$enricher],
            new StandardSchemaMapper()
        );

        $context = (new GenerationContext())->with(new SymfonySerializerContext());

        $schema = $generator->generate(DiscriminatorAnimal::class, $context);
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

    public function testGenerateMapsNestedDiscriminatedTypesThroughSchemaProvider(): void
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializerEnricher = new SerializerPropertyEnricher($metadataFactory);

        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [new PhpDocumentorEnricher(), $serializerEnricher],
            new StandardSchemaMapper()
        );

        $context = (new GenerationContext())->with(new SymfonySerializerContext());

        $schema = $generator->generate(ComplexDiscriminatorContainer::class, $context);
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

    public function testGenerateDoesNotFailWhenVirtualDiscriminatorPropertyIsPresent(): void
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializerEnricher = new SerializerPropertyEnricher($metadataFactory);

        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [$serializerEnricher, new PhpStanEnricher()],
            new StandardSchemaMapper()
        );

        $context = (new GenerationContext())->with(new SymfonySerializerContext());

        $schema = $generator->generate(DiscriminatorCat::class, $context);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertArrayHasKey('type', $schema['properties']);
        self::assertSame(['cat'], $schema['properties']['type']['enum']);
    }

    public function testGenerateMapsDateTimeInterfaceAsJsonEncodeObjectShape(): void
    {
        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [],
            new StandardSchemaMapper()
        );

        $schema = $generator->generate(BasicObject::class);
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

    public function testGenerateCollapsesPrimitiveUnionToTypeArray(): void
    {
        $generator = new SchemaGenerator(
            new ReflectionPropertyDiscoverer(),
            [],
            new StandardSchemaMapper()
        );

        $schema = $generator->generate(BasicObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertIsArray($schema['properties']['union']['type']);
        self::assertSame(['string', 'integer', 'null'], $schema['properties']['union']['type']);
    }
}
