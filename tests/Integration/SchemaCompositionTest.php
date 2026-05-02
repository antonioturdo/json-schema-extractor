<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\EnricherInterface;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\SymfonyValidationEnricher;
use Zeusi\JsonSchemaExtractor\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CollectionValidatedObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\ConflictingCollectionValidatedObject;

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
            new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new AttributeLoader())),
        ]];

        yield 'validator_then_phpdoc' => [[
            new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new AttributeLoader())),
            new PhpDocumentorEnricher(),
        ]];

        yield 'phpstan_then_validator' => [[
            new PhpStanEnricher(),
            new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new AttributeLoader())),
        ]];

        yield 'validator_then_phpstan' => [[
            new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new AttributeLoader())),
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
            new StandardSchemaMapper()
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
                new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new AttributeLoader())),
            ],
            new JsonEncodeSerializationStrategy(),
            new StandardSchemaMapper()
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
}
