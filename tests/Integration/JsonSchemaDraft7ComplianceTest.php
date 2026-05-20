<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Integration;

use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader as SerializerAttributeLoader;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader as ValidatorAttributeLoader;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\EnricherInterface;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\SymfonyValidationEnricher;
use Zeusi\JsonSchemaExtractor\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;
use Zeusi\JsonSchemaExtractor\Serialization\SerializationStrategyInterface;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\BasicObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CollectionValidatedObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\DiscriminatorAnimal;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializableClassUnionPhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializableMapPhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializablePhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\PhpDocObject;

final class JsonSchemaDraft7ComplianceTest extends TestCase
{
    /**
     * @param class-string $className
     * @param list<EnricherInterface> $enrichers
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[DataProvider('schemaProvider')]
    public function testGeneratedSchemasAreDraft7Compliant(
        string $className,
        array $enrichers,
        SerializationStrategyInterface $serializationStrategy
    ): void {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            $enrichers,
            $serializationStrategy,
            new StandardSchemaMapper()
        );

        $schema = $extractor->extract($className);
        $schemaDocument = self::toJsonObject($schema);

        $validation = (new CompliantValidator())->validate($schemaDocument, self::draft7MetaSchema());

        self::assertTrue($validation->isValid(), (string) $validation);
    }

    /**
     * @return iterable<string, array{class-string, list<EnricherInterface>, SerializationStrategyInterface}>
     */
    public static function schemaProvider(): iterable
    {
        yield 'basic json_encode object' => [
            BasicObject::class,
            [],
            new JsonEncodeSerializationStrategy(),
        ];

        yield 'phpdoc-enriched object graph' => [
            PhpDocObject::class,
            [new PhpStanEnricher()],
            new JsonEncodeSerializationStrategy(),
        ];

        yield 'validator collection shape' => [
            CollectionValidatedObject::class,
            [
                new PhpStanEnricher(),
                new SymfonyValidationEnricher(new LazyLoadingMetadataFactory(new ValidatorAttributeLoader())),
            ],
            new JsonEncodeSerializationStrategy(),
        ];

        yield 'jsonserializable object root' => [
            JsonSerializablePhpDocObject::class,
            [new PhpStanEnricher()],
            new JsonEncodeSerializationStrategy(),
        ];

        yield 'jsonserializable map root' => [
            JsonSerializableMapPhpDocObject::class,
            [new PhpStanEnricher()],
            new JsonEncodeSerializationStrategy(),
        ];

        yield 'jsonserializable class union root' => [
            JsonSerializableClassUnionPhpDocObject::class,
            [new PhpStanEnricher()],
            new JsonEncodeSerializationStrategy(),
        ];

        yield 'symfony serializer discriminator' => [
            DiscriminatorAnimal::class,
            [],
            new SymfonySerializerStrategy(new ClassMetadataFactory(new SerializerAttributeLoader())),
        ];
    }

    /**
     * @param array<string, mixed>|object $value
     * @throws \JsonException
     */
    private static function toJsonObject(array|object $value): object
    {
        $json = json_encode($value, \JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertIsObject($decoded);

        return $decoded;
    }

    /**
     * @throws \JsonException
     */
    private static function draft7MetaSchema(): object
    {
        return self::toJsonObject([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            '$id' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'Core schema meta-schema',
            'definitions' => [
                'schemaArray' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['$ref' => '#'],
                ],
                'nonNegativeInteger' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'nonNegativeIntegerDefault0' => [
                    'allOf' => [
                        ['$ref' => '#/definitions/nonNegativeInteger'],
                        ['default' => 0],
                    ],
                ],
                'simpleTypes' => [
                    'enum' => ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string'],
                ],
                'stringArray' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'uniqueItems' => true,
                    'default' => [],
                ],
            ],
            'type' => ['object', 'boolean'],
            'properties' => [
                '$id' => ['type' => 'string', 'format' => 'uri-reference'],
                '$schema' => ['type' => 'string', 'format' => 'uri'],
                '$ref' => ['type' => 'string', 'format' => 'uri-reference'],
                '$comment' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'default' => true,
                'readOnly' => ['type' => 'boolean', 'default' => false],
                'writeOnly' => ['type' => 'boolean', 'default' => false],
                'examples' => ['type' => 'array', 'items' => true],
                'multipleOf' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'maximum' => ['type' => 'number'],
                'exclusiveMaximum' => ['type' => 'number'],
                'minimum' => ['type' => 'number'],
                'exclusiveMinimum' => ['type' => 'number'],
                'maxLength' => ['$ref' => '#/definitions/nonNegativeInteger'],
                'minLength' => ['$ref' => '#/definitions/nonNegativeIntegerDefault0'],
                'pattern' => ['type' => 'string', 'format' => 'regex'],
                'additionalItems' => ['$ref' => '#'],
                'items' => [
                    'anyOf' => [
                        ['$ref' => '#'],
                        ['$ref' => '#/definitions/schemaArray'],
                    ],
                    'default' => true,
                ],
                'maxItems' => ['$ref' => '#/definitions/nonNegativeInteger'],
                'minItems' => ['$ref' => '#/definitions/nonNegativeIntegerDefault0'],
                'uniqueItems' => ['type' => 'boolean', 'default' => false],
                'contains' => ['$ref' => '#'],
                'maxProperties' => ['$ref' => '#/definitions/nonNegativeInteger'],
                'minProperties' => ['$ref' => '#/definitions/nonNegativeIntegerDefault0'],
                'required' => ['$ref' => '#/definitions/stringArray'],
                'additionalProperties' => ['$ref' => '#'],
                'definitions' => [
                    'type' => 'object',
                    'additionalProperties' => ['$ref' => '#'],
                    'default' => new \stdClass(),
                ],
                'properties' => [
                    'type' => 'object',
                    'additionalProperties' => ['$ref' => '#'],
                    'default' => new \stdClass(),
                ],
                'patternProperties' => [
                    'type' => 'object',
                    'additionalProperties' => ['$ref' => '#'],
                    'propertyNames' => ['format' => 'regex'],
                    'default' => new \stdClass(),
                ],
                'dependencies' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'anyOf' => [
                            ['$ref' => '#'],
                            ['$ref' => '#/definitions/stringArray'],
                        ],
                    ],
                ],
                'propertyNames' => ['$ref' => '#'],
                'const' => true,
                'enum' => [
                    'type' => 'array',
                    'items' => true,
                    'minItems' => 1,
                    'uniqueItems' => true,
                ],
                'type' => [
                    'anyOf' => [
                        ['$ref' => '#/definitions/simpleTypes'],
                        [
                            'type' => 'array',
                            'items' => ['$ref' => '#/definitions/simpleTypes'],
                            'minItems' => 1,
                            'uniqueItems' => true,
                        ],
                    ],
                ],
                'format' => ['type' => 'string'],
                'contentMediaType' => ['type' => 'string'],
                'contentEncoding' => ['type' => 'string'],
                'if' => ['$ref' => '#'],
                'then' => ['$ref' => '#'],
                'else' => ['$ref' => '#'],
                'allOf' => ['$ref' => '#/definitions/schemaArray'],
                'anyOf' => ['$ref' => '#/definitions/schemaArray'],
                'oneOf' => ['$ref' => '#/definitions/schemaArray'],
                'not' => ['$ref' => '#'],
            ],
            'default' => true,
        ]);
    }
}
