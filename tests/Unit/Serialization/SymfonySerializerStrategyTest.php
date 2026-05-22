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
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\MethodDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\PropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\Types;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Serialization\JsonSerializableProjection;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CustomNormalizedObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\DiscriminatorCat;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\JsonSerializablePhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\SerializerObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\SymfonySerializer\CustomSerializationStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\SymfonySerializer\NumberNormalizerStub;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(SymfonySerializerStrategy::class)]
#[CoversClass(JsonSerializableProjection::class)]
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

        $projectedDefinition = $this->requireRootObject($this->strategy->project($definition, new ExtractionContext()));

        self::assertArrayNotHasKey('name', $projectedDefinition->properties);
        self::assertSame('renamed_field', $this->requireSerializedProperty($projectedDefinition, 'renamed_field')->name);
        self::assertArrayHasKey('union', $projectedDefinition->properties);
    }

    public function testProjectFiltersPropertiesByGroupsWhenContextProvided(): void
    {
        $definition = $this->discoverer->discover(SerializerObject::class);
        $context = (new ExtractionContext())->with(new SymfonySerializerContext(context: ['groups' => ['read']]));

        $definition = $this->requireRootObject($this->strategy->project($definition, $context));

        self::assertArrayHasKey('id', $definition->properties);
        self::assertArrayNotHasKey('name', $definition->properties);
        self::assertArrayNotHasKey('union', $definition->properties);
    }

    public function testProjectAddsSymfonyDiscriminatorPropertyForConcreteClasses(): void
    {
        $definition = $this->discoverer->discover(DiscriminatorCat::class);
        $context = (new ExtractionContext())->with(new SymfonySerializerContext(context: ['groups' => ['read']]));

        $definition = $this->requireRootObject($this->strategy->project($definition, $context));

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
        $this->assertPlainStringField($this->requireSerializedProperty($definition, 'message'));
        $this->assertStringField($this->requireSerializedProperty($definition, 'file'), pattern: '^data:');
        $this->assertProblemShape($this->requireSerializedProperty($definition, 'violations'), ['type', 'title', 'violations']);
        $this->assertFormErrorShape($this->requireSerializedProperty($definition, 'form'));
        $this->assertProblemShape($this->requireSerializedProperty($definition, 'problem'), ['type', 'title', 'status', 'detail']);
        $this->assertStringField($this->requireSerializedProperty($definition, 'uuid'), 'uuid');
        $this->assertStringField($this->requireSerializedProperty($definition, 'ulid'));
        $this->assertStringField($this->requireSerializedProperty($definition, 'base58Uuid'));
    }

    public function testProjectMapsSymfonyNumberNormalizerTypesToStringsWhenAvailable(): void
    {
        if (!class_exists('GMP')) {
            self::markTestSkipped('The GMP extension is required for this test.');
        }

        if (!class_exists('Symfony\Component\Serializer\Normalizer\NumberNormalizer')) {
            class_alias(NumberNormalizerStub::class, 'Symfony\Component\Serializer\Normalizer\NumberNormalizer');
        }

        $definition = new ClassDefinition(className: SerializerObject::class);
        $amount = new PropertyDefinition('amount');
        $amount->setType(new ClassLikeType('GMP'));
        $definition->addProperty($amount);

        $projectedDefinition = $this->requireRootObject($this->strategy->project($definition, new ExtractionContext()));

        $this->assertPlainStringField($this->requireSerializedProperty($projectedDefinition, 'amount'));
    }

    public function testProjectCanBeDecoratedForRuntimeSerializerCustomizations(): void
    {
        $definition = $this->discoverer->discover(CustomNormalizedObject::class);
        $strategy = new CustomSerializationStrategy($this->strategy);

        $projectedDefinition = $this->requireRootObject($strategy->project($definition, new ExtractionContext()));

        $this->assertPlainStringField($this->requireSerializedProperty($projectedDefinition, 'amount'));
        $this->assertPlainStringField($this->requireSerializedProperty($projectedDefinition, 'owner'));
        $this->assertPlainStringField($this->requireSerializedProperty($projectedDefinition, 'label'));
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

    public function testProjectUsesJsonSerializableNormalizerShapeWhenAvailable(): void
    {
        $definition = new ClassDefinition(className: JsonSerializablePhpDocObject::class);

        $internal = new PropertyDefinition('internal');
        $internal->setType(Types::string());
        $definition->addProperty($internal);

        $shape = new InlineObjectDefinition(id: JsonSerializablePhpDocObject::class . '::jsonSerialize() return');
        $id = new InlineFieldDefinition('id', required: true);
        $id->setType(Types::int());
        $shape->addProperty($id);

        $name = new InlineFieldDefinition('name', required: true);
        $name->setType(Types::string());
        $shape->addProperty($name);

        $definition->addMethod(new MethodDefinition('jsonSerialize', new InlineObjectType($shape)));
        $projectedDefinition = $this->requireRootObject($this->strategy->project($definition, new ExtractionContext()));

        self::assertArrayHasKey('id', $projectedDefinition->properties);
        self::assertArrayHasKey('name', $projectedDefinition->properties);
        self::assertArrayNotHasKey('internal', $projectedDefinition->properties);

        $idProperty = $this->requireSerializedProperty($projectedDefinition, 'id');
        $idType = $this->requireType($idProperty->type, 'Expected id to have a type.');
        $this->assertBuiltin($idType, 'int');

        $nameProperty = $this->requireSerializedProperty($projectedDefinition, 'name');
        $nameType = $this->requireType($nameProperty->type, 'Expected name to have a type.');
        $this->assertBuiltin($nameType, 'string');
    }

    public function testProjectFailsWhenJsonSerializableNormalizerShapeIsMissing(): void
    {
        $definition = $this->discoverer->discover(JsonSerializablePhpDocObject::class);

        $this->expectException(\LogicException::class);

        $this->strategy->project($definition, new ExtractionContext());
    }

    private function enrichSerializerObject(?ExtractionContext $context = null): SerializedObjectDefinition
    {
        $context ??= new ExtractionContext();
        $definition = $this->discoverer->discover(SerializerObject::class);

        (new PhpStanEnricher())->enrich($definition, $context, new EnrichmentRuntime());
        $definition = $this->requireRootObject($this->strategy->project($definition, $context));

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

    private function assertPlainStringField(SerializedPropertyDefinition $field): void
    {
        $type = $this->requireType($field->type, 'Expected field to have a type expression.');
        $this->assertBuiltin($type, 'string');
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

    private function assertFormErrorShape(SerializedPropertyDefinition $field): void
    {
        $expr = $this->unwrapDecorated($this->requireType($field->type, 'Expected form error field to have a type expression.'));
        self::assertInstanceOf(SerializedObjectType::class, $expr);

        $shape = $expr->shape;
        self::assertFalse($shape->additionalProperties);

        foreach (['title', 'type'] as $requiredField) {
            self::assertTrue($this->requireSerializedProperty($shape, $requiredField)->required);
        }

        $code = $this->requireSerializedProperty($shape, 'code');
        $errorsField = $this->requireSerializedProperty($shape, 'errors');
        $children = $this->requireSerializedProperty($shape, 'children');

        self::assertTrue($code->required);
        self::assertTrue($errorsField->required);
        self::assertFalse($children->required);
        self::assertInstanceOf(UnionType::class, $this->requireType($code->type, 'Expected code field to have a type expression.'));
        self::assertSame(['int', 'null'], $this->collectTypeNames($code->type));

        $errors = $this->unwrapDecorated($this->requireType(
            $errorsField->type,
            'Expected form errors field to have a type expression.'
        ));
        self::assertInstanceOf(ArrayType::class, $errors);

        $errorItem = $this->unwrapDecorated($errors->type);
        self::assertInstanceOf(SerializedObjectType::class, $errorItem);
        self::assertTrue($this->requireSerializedProperty($errorItem->shape, 'message')->required);
        self::assertTrue($this->requireSerializedProperty($errorItem->shape, 'cause')->required);

        $childrenType = $this->unwrapDecorated($this->requireType(
            $children->type,
            'Expected form children field to have a type expression.'
        ));
        self::assertInstanceOf(MapType::class, $childrenType);
    }

    private function requireSerializedProperty(SerializedObjectDefinition $shape, string $propertyName): SerializedPropertyDefinition
    {
        $property = $shape->properties[$propertyName] ?? null;
        if ($property === null) {
            self::fail(\sprintf('Expected property "%s" to exist.', $propertyName));
        }

        return $property;
    }

    private function requireRootObject(SerializedPayloadDefinition $payload): SerializedObjectDefinition
    {
        $type = $this->unwrapDecorated($payload->type);
        self::assertInstanceOf(SerializedObjectType::class, $type);

        return $type->shape;
    }
}
