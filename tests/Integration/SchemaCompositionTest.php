<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader as SerializerAttributeLoader;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader as ValidatorAttributeLoader;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\EnricherInterface;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\SymfonyValidationEnricher;
use Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapper;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CollectionValidatedObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\ConflictingCollectionValidatedObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializableClassUnionPhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializableListPhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializableMapPhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializableNonEmptyStringPhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializablePhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializableStringObject;

#[CoversClass(SchemaExtractor::class)]
class SchemaCompositionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: list<EnricherInterface>}>
     */
    public static function simpleCollectionEnricherOrdersProvider(): iterable
    {
        yield 'phpdoc_then_validator' => [[
            new PhpDocumentorEnricher(),
            new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new ValidatorAttributeLoader())),
        ]];

        yield 'validator_then_phpdoc' => [[
            new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new ValidatorAttributeLoader())),
            new PhpDocumentorEnricher(),
        ]];

        yield 'phpstan_then_validator' => [[
            new PhpStanEnricher(),
            new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new ValidatorAttributeLoader())),
        ]];

        yield 'validator_then_phpstan' => [[
            new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new ValidatorAttributeLoader())),
            new PhpStanEnricher(),
        ]];
    }

    /**
     * @param list<EnricherInterface> $enrichers
     */
    #[DataProvider('simpleCollectionEnricherOrdersProvider')]
    public function testGenerateMergesShapeAndCollectionConstraintsForSimpleCollection(array $enrichers): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            $enrichers,
            new JsonEncodeSerializationStrategy(),
            new StandardJsonSchemaMapper()
        );

        $schema = $extractor->extract(CollectionValidatedObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        $simpleCollection = $schema['properties']['simpleCollection'];
        self::assertSame('object', $simpleCollection['type']);
        self::assertArrayHasKey('additionalProperties', $simpleCollection);
        self::assertFalse($simpleCollection['additionalProperties']);

        self::assertSame('string', $simpleCollection['properties']['email']['type']);
        self::assertSame('email', $simpleCollection['properties']['email']['format']);
        self::assertContains('email', $simpleCollection['required']);

        self::assertSame('integer', $simpleCollection['properties']['age']['type']);
        self::assertSame(18, $simpleCollection['properties']['age']['minimum']);
        self::assertSame(99, $simpleCollection['properties']['age']['maximum']);
        self::assertContains('age', $simpleCollection['required']);
    }

    public function testGenerateNeedsPolicyForConflictingPhpDocTypeAndCollectionConstraint(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [
                new PhpDocumentorEnricher(),
                new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new ValidatorAttributeLoader())),
            ],
            new JsonEncodeSerializationStrategy(),
            new StandardJsonSchemaMapper()
        );

        $schema = $extractor->extract(ConflictingCollectionValidatedObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        $simpleCollection = $schema['properties']['simpleCollection'];
        self::assertSame('object', $simpleCollection['type']);
        self::assertSame('integer', $simpleCollection['properties']['email']['type']);
        self::assertSame('email', $simpleCollection['properties']['email']['format']);
        self::assertContains('email', $simpleCollection['required']);
    }

    public function testGenerateUsesJsonSerializablePhpDocReturnShape(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [new PhpStanEnricher()],
            new JsonEncodeSerializationStrategy(),
            new StandardJsonSchemaMapper()
        );

        $schema = $extractor->extract(JsonSerializablePhpDocObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertArrayHasKey('id', $schema['properties']);
        self::assertArrayHasKey('name', $schema['properties']);
        self::assertArrayNotHasKey('internal', $schema['properties']);
        self::assertSame('integer', $schema['properties']['id']['type']);
        self::assertSame('string', $schema['properties']['name']['type']);
    }

    public function testGenerateUsesJsonSerializablePhpDocReturnShapeWithSymfonySerializer(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [new PhpStanEnricher()],
            new SymfonySerializerStrategy(new ClassMetadataFactory(new SerializerAttributeLoader())),
            new StandardJsonSchemaMapper()
        );

        $schema = $extractor->extract(JsonSerializablePhpDocObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertArrayHasKey('id', $schema['properties']);
        self::assertArrayHasKey('name', $schema['properties']);
        self::assertArrayNotHasKey('internal', $schema['properties']);
        self::assertSame('integer', $schema['properties']['id']['type']);
        self::assertSame('string', $schema['properties']['name']['type']);
    }

    public function testGenerateUsesJsonSerializableClassUnionReturnType(): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [new PhpStanEnricher()],
            new JsonEncodeSerializationStrategy(),
            new StandardJsonSchemaMapper()
        );

        $schema = $extractor->extract(JsonSerializableClassUnionPhpDocObject::class);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertSame('#/definitions/BasicObject', $schema['anyOf'][0]['$ref']);
        self::assertSame('string', $schema['anyOf'][1]['type']);
        self::assertSame('object', $schema['definitions']['BasicObject']['type']);
    }

    /**
     * @param class-string $className
     * @param list<EnricherInterface> $enrichers
     * @param array<string, mixed> $expectedSchema
     */
    #[DataProvider('jsonSerializableNonObjectPayloadProvider')]
    public function testGenerateUsesJsonSerializableNonObjectReturnTypes(string $className, array $enrichers, array $expectedSchema): void
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            $enrichers,
            new JsonEncodeSerializationStrategy(),
            new StandardJsonSchemaMapper()
        );

        $schema = $extractor->extract($className);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertSchemaContains($expectedSchema, $schema);
        self::assertArrayNotHasKey('properties', $schema);
    }

    /**
     * @return iterable<string, array{class-string, list<EnricherInterface>, array<string, mixed>}>
     */
    public static function jsonSerializableNonObjectPayloadProvider(): iterable
    {
        yield 'native scalar' => [
            JsonSerializableStringObject::class,
            [],
            ['type' => 'string'],
        ];

        yield 'phpdoc list' => [
            JsonSerializableListPhpDocObject::class,
            [new PhpStanEnricher()],
            ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        yield 'phpdoc map' => [
            JsonSerializableMapPhpDocObject::class,
            [new PhpStanEnricher()],
            ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
        ];

        yield 'phpdoc decorated scalar' => [
            JsonSerializableNonEmptyStringPhpDocObject::class,
            [new PhpStanEnricher()],
            ['type' => 'string', 'minLength' => 1],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private static function assertSchemaContains(array $expected, array $actual): void
    {
        foreach ($expected as $key => $expectedValue) {
            self::assertArrayHasKey($key, $actual);
            if (\is_array($expectedValue)) {
                self::assertIsArray($actual[$key]);
                /** @var array<string, mixed> $actualValue */
                $actualValue = $actual[$key];
                self::assertSchemaContains($expectedValue, $actualValue);
                continue;
            }

            self::assertSame($expectedValue, $actual[$key]);
        }
    }
}
