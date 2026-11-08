<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Integration;

use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapper;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CustomerProfile;

/**
 * Compares generated schemas with what Symfony Serializer actually emits.
 *
 * The reference is `ObjectNormalizer`, the object normalizer of a standard Symfony
 * setup: it exposes public properties and accessor methods, and nothing else.
 */
#[CoversClass(SymfonySerializerStrategy::class)]
final class SymfonySerializerOutputTest extends TestCase
{
    /**
     * @throws \JsonException
     */
    public function testSchemaDescribesExactlyTheObjectNormalizerOutput(): void
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());

        $payload = (new Serializer([new ObjectNormalizer($metadataFactory)]))->normalize(
            new CustomerProfile('Ada Lovelace', 'ada@example.com', true, 'Prefers email contact')
        );
        self::assertIsArray($payload);

        $schema = (new SchemaExtractor(
            new ReflectionDiscoverer(),
            [],
            new SymfonySerializerStrategy($metadataFactory),
            new StandardJsonSchemaMapper()
        ))->extract(CustomerProfile::class)->schema;
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */

        self::assertEqualsCanonicalizing(array_keys($payload), array_keys($schema['properties']));

        $validation = (new CompliantValidator())->validate(self::toJsonObject($payload), self::toJsonObject($schema));
        self::assertTrue($validation->isValid(), 'The serialized payload does not validate against its own schema.');
    }

    /**
     * @param array<string, mixed>|object $value
     *
     * @throws \JsonException
     */
    private static function toJsonObject(array|object $value): object
    {
        $decoded = json_decode(json_encode($value, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        self::assertIsObject($decoded);

        return $decoded;
    }
}
