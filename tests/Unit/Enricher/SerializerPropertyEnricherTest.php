<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\UidNormalizer;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Context\SymfonySerializerContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\FieldDefinitionInterface;
use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\DecoratedType;
use Zeusi\JsonSchemaGenerator\Definition\Type\InlineObjectType;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\SerializerPropertyEnricher;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\DiscriminatorCat;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\SerializerObject;
use Zeusi\JsonSchemaGenerator\Tests\Support\TypeExprTestHelperTrait;

#[CoversClass(SerializerPropertyEnricher::class)]
class SerializerPropertyEnricherTest extends TestCase
{
    use TypeExprTestHelperTrait;

    private ReflectionPropertyDiscoverer $discoverer;
    private SerializerPropertyEnricher $enricher;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionPropertyDiscoverer();
        $this->enricher = new SerializerPropertyEnricher(
            new ClassMetadataFactory(new AttributeLoader())
        );
    }

    public function testEnrichAppliesSerializedNameWithoutGroupsFiltering(): void
    {
        $definition = $this->discoverer->discover(SerializerObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        self::assertSame('renamed_field', $definition->properties['name']->getSerializedName());
        self::assertArrayHasKey('union', $definition->properties);
    }

    public function testEnrichFiltersPropertiesByGroupsWhenContextProvided(): void
    {
        $definition = $this->discoverer->discover(SerializerObject::class);
        $context = (new GenerationContext())->with(new SymfonySerializerContext(context: ['groups' => ['read']]));

        $this->enricher->enrich($definition, $context);

        self::assertArrayHasKey('id', $definition->properties);
        self::assertArrayNotHasKey('name', $definition->properties);
        self::assertArrayNotHasKey('union', $definition->properties);
    }

    public function testEnrichAddsSymfonyDiscriminatorPropertyForConcreteClasses(): void
    {
        $definition = $this->discoverer->discover(DiscriminatorCat::class);
        $context = (new GenerationContext())->with(new SymfonySerializerContext(context: ['groups' => ['read']]));

        $this->enricher->enrich($definition, $context);

        $typeField = $definition->getProperty('type');
        if ($typeField === null) {
            self::fail('Expected discriminator field "type" to be present.');
        }

        self::assertTrue($typeField->isRequired());

        $expr = $this->requireTypeExpr($typeField->getTypeExpr(), 'Expected discriminator field "type" to have a typeExpr.');

        $decorated = $this->findFirstNonNullDecorated($expr);
        self::assertNotNull($decorated);
        self::assertSame(['cat'], $decorated->constraints->enum);

        $inner = $this->unwrapDecorated($decorated->type);
        self::assertInstanceOf(BuiltinType::class, $inner);
        self::assertSame('string', $inner->name);
    }

    public function testEnrichMapsKnownSymfonyNormalizerTypesToSerializedTypeExpressions(): void
    {
        $definition = $this->enrichSerializerObject();

        $this->assertStringField($definition->properties['createdAt'], 'date-time');
        $this->assertStringField($definition->properties['birthDate'], 'date');
        $this->assertArrayOfStringField($definition->properties['events'], 'date-time');
        $this->assertStringField($definition->properties['timezone']);
        $this->assertStringField($definition->properties['duration'], 'duration');
        $this->assertStringField($definition->properties['customDuration']);
        $this->assertStringField($definition->properties['message']);
        $this->assertStringField($definition->properties['file'], pattern: '^data:');
        $this->assertProblemShape($definition->properties['violations'], ['type', 'title', 'violations']);
        $this->assertProblemShape($definition->properties['problem'], ['type', 'title', 'status', 'detail']);
        $this->assertStringField($definition->properties['uuid'], 'uuid');
        $this->assertStringField($definition->properties['ulid']);
        $this->assertStringField($definition->properties['base58Uuid']);
    }

    public function testEnrichUsesSymfonySerializerContextForKnownNormalizerFormats(): void
    {
        $context = (new GenerationContext())->with(new SymfonySerializerContext(context: [
            DateTimeNormalizer::FORMAT_KEY => 'Y-m-d',
            UidNormalizer::NORMALIZATION_FORMAT_KEY => UidNormalizer::NORMALIZATION_FORMAT_BASE32,
        ]));

        $definition = $this->enrichSerializerObject($context);

        $this->assertStringField($definition->properties['createdAt'], 'date');
        $this->assertArrayOfStringField($definition->properties['events'], 'date');
        $this->assertStringField($definition->properties['uuid']);
        $this->assertStringField($definition->properties['base58Uuid']);
    }

    private function enrichSerializerObject(?GenerationContext $context = null): ClassDefinition
    {
        $context ??= new GenerationContext();
        $definition = $this->discoverer->discover(SerializerObject::class);

        (new PhpStanEnricher())->enrich($definition, $context);
        $this->enricher->enrich($definition, $context);

        return $definition;
    }

    private function assertStringField(FieldDefinitionInterface $field, ?string $format = null, ?string $pattern = null): void
    {
        $decorated = $this->findFirstNonNullDecorated($field->getTypeExpr());
        self::assertNotNull($decorated);

        $inner = $this->unwrapDecorated($decorated->type);
        self::assertInstanceOf(BuiltinType::class, $inner);
        self::assertSame('string', $inner->name);
        self::assertSame($format, $decorated->annotations?->format);
        self::assertSame($pattern, $decorated->constraints->pattern);
    }

    private function assertArrayOfStringField(FieldDefinitionInterface $field, ?string $format = null): void
    {
        $array = $this->unwrapDecorated($this->requireTypeExpr($field->getTypeExpr(), 'Expected field to have a type expression.'));
        self::assertInstanceOf(ArrayType::class, $array);
        self::assertInstanceOf(DecoratedType::class, $array->type);

        $inner = $this->unwrapDecorated($array->type->type);
        self::assertInstanceOf(BuiltinType::class, $inner);
        self::assertSame('string', $inner->name);
        self::assertSame($format, $array->type->annotations?->format);
    }

    /**
     * @param list<string> $requiredFields
     */
    private function assertProblemShape(FieldDefinitionInterface $field, array $requiredFields): void
    {
        $expr = $this->unwrapDecorated($this->requireTypeExpr($field->getTypeExpr(), 'Expected problem field to have a type expression.'));
        self::assertInstanceOf(InlineObjectType::class, $expr);

        $shape = $expr->shape;
        self::assertFalse($shape->additionalProperties);
        foreach ($requiredFields as $requiredField) {
            $property = $this->requireInlineProperty($shape, $requiredField);
            self::assertTrue($property->isRequired());
        }

        $violationsField = $shape->getProperty('violations');
        if ($violationsField !== null) {
            $violations = $this->unwrapDecorated($this->requireTypeExpr(
                $violationsField->getTypeExpr(),
                'Expected violations field to have a type expression.'
            ));
            self::assertInstanceOf(ArrayType::class, $violations);
        }
    }

    private function requireInlineProperty(InlineObjectDefinition $shape, string $propertyName): FieldDefinitionInterface
    {
        $property = $shape->getProperty($propertyName);
        if ($property === null) {
            self::fail(\sprintf('Expected inline property "%s" to exist.', $propertyName));
        }

        return $property;
    }
}
