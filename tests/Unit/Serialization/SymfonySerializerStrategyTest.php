<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Serialization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\UidNormalizer;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\DiscriminatorCat;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\SerializerObject;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(SymfonySerializerStrategy::class)]
class SymfonySerializerStrategyTest extends TestCase
{
    use TypeTestHelperTrait;

    private ReflectionDiscoverer $discoverer;
    private SymfonySerializerStrategy $strategy;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionDiscoverer();
        $this->strategy = new SymfonySerializerStrategy(
            new ClassMetadataFactory(new AttributeLoader())
        );
    }

    public function testProjectAppliesSerializedNameWithoutGroupsFiltering(): void
    {
        $definition = $this->discoverer->discover(SerializerObject::class);

        $projectedDefinition = $this->strategy->project($definition, new ExtractionContext());

        self::assertArrayNotHasKey('name', $projectedDefinition->properties);
        self::assertSame('renamed_field', $this->requireSerializedProperty($projectedDefinition, 'renamed_field')->name);
        self::assertArrayHasKey('union', $projectedDefinition->properties);
    }

    public function testProjectFiltersPropertiesByGroupsWhenContextProvided(): void
    {
        $definition = $this->discoverer->discover(SerializerObject::class);
        $context = (new ExtractionContext())->with(new SymfonySerializerContext(context: ['groups' => ['read']]));

        $definition = $this->strategy->project($definition, $context);

        self::assertArrayHasKey('id', $definition->properties);
        self::assertArrayNotHasKey('name', $definition->properties);
        self::assertArrayNotHasKey('union', $definition->properties);
    }

    public function testProjectAddsSymfonyDiscriminatorPropertyForConcreteClasses(): void
    {
        $definition = $this->discoverer->discover(DiscriminatorCat::class);
        $context = (new ExtractionContext())->with(new SymfonySerializerContext(context: ['groups' => ['read']]));

        $definition = $this->strategy->project($definition, $context);

        $typeField = $this->requireSerializedProperty($definition, 'type');
        self::assertTrue($typeField->required);

        $expr = $this->requireType($typeField->type, 'Expected discriminator field "type" to have a type.');

        $decorated = $this->findFirstNonNullDecorated($expr);
        self::assertNotNull($decorated);
        self::assertSame(['cat'], $decorated->constraints->enum);

        $inner = $this->unwrapDecorated($decorated->type);
        self::assertInstanceOf(BuiltinType::class, $inner);
        self::assertSame('string', $inner->name);
    }

    public function testProjectMapsKnownSymfonyNormalizerTypesToSerializedType(): void
    {
        $definition = $this->enrichSerializerObject();

        $this->assertStringField($this->requireSerializedProperty($definition, 'createdAt'), 'date-time');
        $this->assertStringField($this->requireSerializedProperty($definition, 'birthDate'), 'date');
        $this->assertArrayOfStringField($this->requireSerializedProperty($definition, 'events'), 'date-time');
        $this->assertStringField($this->requireSerializedProperty($definition, 'timezone'));
        $this->assertStringField($this->requireSerializedProperty($definition, 'duration'), 'duration');
        $this->assertStringField($this->requireSerializedProperty($definition, 'customDuration'));
        $this->assertStringField($this->requireSerializedProperty($definition, 'message'));
        $this->assertStringField($this->requireSerializedProperty($definition, 'file'), pattern: '^data:');
        $this->assertProblemShape($this->requireSerializedProperty($definition, 'violations'), ['type', 'title', 'violations']);
        $this->assertProblemShape($this->requireSerializedProperty($definition, 'problem'), ['type', 'title', 'status', 'detail']);
        $this->assertStringField($this->requireSerializedProperty($definition, 'uuid'), 'uuid');
        $this->assertStringField($this->requireSerializedProperty($definition, 'ulid'));
        $this->assertStringField($this->requireSerializedProperty($definition, 'base58Uuid'));
    }

    public function testProjectUsesSymfonySerializerContextForKnownNormalizerFormats(): void
    {
        $context = (new ExtractionContext())->with(new SymfonySerializerContext(context: [
            DateTimeNormalizer::FORMAT_KEY => 'Y-m-d',
            UidNormalizer::NORMALIZATION_FORMAT_KEY => UidNormalizer::NORMALIZATION_FORMAT_BASE32,
        ]));

        $definition = $this->enrichSerializerObject($context);

        $this->assertStringField($this->requireSerializedProperty($definition, 'createdAt'), 'date');
        $this->assertArrayOfStringField($this->requireSerializedProperty($definition, 'events'), 'date');
        $this->assertStringField($this->requireSerializedProperty($definition, 'uuid'));
        $this->assertStringField($this->requireSerializedProperty($definition, 'base58Uuid'));
    }

    public function testProjectSerializesDefaultsWithSymfonyJsonEncodeOptions(): void
    {
        $defaultDefinition = $this->enrichSerializerObject();
        $defaultPreferences = $this->findFirstNonNullDecorated($this->requireSerializedProperty($defaultDefinition, 'preferences')->type);
        self::assertNotNull($defaultPreferences);
        self::assertSame([], $defaultPreferences->annotations?->default);

        $context = (new ExtractionContext())->with(new SymfonySerializerContext(context: [
            JsonEncode::OPTIONS => \JSON_FORCE_OBJECT,
        ]));

        $forceObjectDefinition = $this->enrichSerializerObject($context);
        $forceObjectPreferences = $this->findFirstNonNullDecorated($this->requireSerializedProperty($forceObjectDefinition, 'preferences')->type);
        self::assertNotNull($forceObjectPreferences);
        self::assertEquals(new \stdClass(), $forceObjectPreferences->annotations?->default);
    }

    private function enrichSerializerObject(?ExtractionContext $context = null): SerializedObjectDefinition
    {
        $context ??= new ExtractionContext();
        $definition = $this->discoverer->discover(SerializerObject::class);

        (new PhpStanEnricher())->enrich($definition, $context, new EnrichmentRuntime());
        $definition = $this->strategy->project($definition, $context);

        return $definition;
    }

    private function assertStringField(SerializedPropertyDefinition $field, ?string $format = null, ?string $pattern = null): void
    {
        $decorated = $this->findFirstNonNullDecorated($field->type);
        self::assertNotNull($decorated);

        $inner = $this->unwrapDecorated($decorated->type);
        self::assertInstanceOf(BuiltinType::class, $inner);
        self::assertSame('string', $inner->name);
        self::assertSame($format, $decorated->annotations?->format);
        self::assertSame($pattern, $decorated->constraints->pattern);
    }

    private function assertArrayOfStringField(SerializedPropertyDefinition $field, ?string $format = null): void
    {
        $array = $this->unwrapDecorated($this->requireType($field->type, 'Expected field to have a type expression.'));
        self::assertInstanceOf(ArrayType::class, $array);

        $decoratedType = $array->type;
        self::assertInstanceOf(DecoratedType::class, $decoratedType);
        /** @var DecoratedType $decoratedType */

        $inner = $this->unwrapDecorated($decoratedType->type);
        self::assertInstanceOf(BuiltinType::class, $inner);
        self::assertSame('string', $inner->name);
        self::assertSame($format, $decoratedType->annotations?->format);
    }

    /**
     * @param list<string> $requiredFields
     */
    private function assertProblemShape(SerializedPropertyDefinition $field, array $requiredFields): void
    {
        $expr = $this->unwrapDecorated($this->requireType($field->type, 'Expected problem field to have a type expression.'));
        self::assertInstanceOf(SerializedObjectType::class, $expr);

        $shape = $expr->shape;
        self::assertFalse($shape->additionalProperties);
        foreach ($requiredFields as $requiredField) {
            $property = $this->requireSerializedProperty($shape, $requiredField);
            self::assertTrue($property->required);
        }

        $violationsField = $shape->properties['violations'] ?? null;
        if ($violationsField !== null) {
            $violations = $this->unwrapDecorated($this->requireType(
                $violationsField->type,
                'Expected violations field to have a type expression.'
            ));
            self::assertInstanceOf(ArrayType::class, $violations);
        }
    }

    private function requireSerializedProperty(SerializedObjectDefinition $shape, string $propertyName): SerializedPropertyDefinition
    {
        $property = $shape->properties[$propertyName] ?? null;
        if ($property === null) {
            self::fail(\sprintf('Expected property "%s" to exist.', $propertyName));
        }

        return $property;
    }
}
