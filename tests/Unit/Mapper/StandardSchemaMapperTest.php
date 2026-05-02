<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Mapper\ClassReferenceStrategy;
use Zeusi\JsonSchemaExtractor\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Schema;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\SchemaType;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\FieldDefinitionInterface;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\PropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\Types;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionSemantics;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\BasicObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\StatusEnum;

final class StandardSchemaMapperTest extends TestCase
{
    public function testMapUsesTypeWhenProvided(): void
    {
        $definition = new ClassDefinition('MyDto');

        $union = new PropertyDefinition('union');
        $union->setType(Types::union(Types::string(), Types::null()));
        $definition->addProperty($union);

        $nestedUnion = new PropertyDefinition('nestedUnion');
        $nestedUnion->setType(Types::union(
            Types::union(Types::string(), Types::bool()),
            Types::null()
        ));
        $definition->addProperty($nestedUnion);

        $decorated = new PropertyDefinition('decorated');
        $decorated->setType(Types::decorated(
            Types::arrayOf(Types::decorated(
                Types::string(),
                new TypeConstraints(pattern: '^[a-z]+$')
            )),
            new TypeConstraints(minItems: 1),
            new TypeAnnotations(title: 'Decorated', description: 'A decorated array')
        ));
        $definition->addProperty($decorated);

        $inlineObject = new InlineObjectDefinition(
            id: 'inline-1',
            additionalProperties: false
        );
        $inlineObject->addProperty(new InlineFieldDefinition(
            fieldName: 'foo',
            required: true
        ));
        $inlineObject->getProperty('foo')?->setType(Types::string());

        $inline = new PropertyDefinition('inline');
        $inline->setType(Types::decorated(
            Types::inlineObject($inlineObject),
            annotations: new TypeAnnotations(default: [])
        ));
        $definition->addProperty($inline);

        $untyped = new PropertyDefinition('untyped');
        $definition->addProperty($untyped);

        $schemaProvider = static fn(string $className): Schema => (new Schema())->setType(SchemaType::OBJECT);

        $serialized = self::serializeSchema((new StandardSchemaMapper())->map(self::serialized($definition), $schemaProvider));

        self::assertSame('object', $serialized['type']);

        self::assertSame(['string', 'null'], $serialized['properties']['union']['type']);
        self::assertSame(['string', 'boolean', 'null'], $serialized['properties']['nestedUnion']['type']);

        self::assertSame('array', $serialized['properties']['decorated']['type']);
        self::assertSame(1, $serialized['properties']['decorated']['minItems']);
        self::assertSame('Decorated', $serialized['properties']['decorated']['title']);
        self::assertSame('A decorated array', $serialized['properties']['decorated']['description']);
        self::assertSame('string', $serialized['properties']['decorated']['items']['type']);
        self::assertSame('^[a-z]+$', $serialized['properties']['decorated']['items']['pattern']);

        self::assertSame('object', $serialized['properties']['inline']['type']);
        self::assertSame(false, $serialized['properties']['inline']['additionalProperties']);
        self::assertSame('string', $serialized['properties']['inline']['properties']['foo']['type']);
        self::assertContains('foo', $serialized['properties']['inline']['required']);
        self::assertArrayNotHasKey('default', $serialized['properties']['inline']);

        self::assertEquals(new \stdClass(), $serialized['properties']['untyped']);
    }

    public function testMapCanReferenceClassLikeTypesThroughDefinitions(): void
    {
        $definition = new ClassDefinition('MyDto');

        $related = new PropertyDefinition('related');
        $related->setType(Types::classLike(BasicObject::class));
        $definition->addProperty($related);

        $schemaProvider = static fn(string $className): Schema => (new Schema())
            ->setType(SchemaType::OBJECT)
            ->addProperty('id', (new Schema())->setType(SchemaType::INTEGER), true);

        $serialized = self::serializeSchema((new StandardSchemaMapper(ClassReferenceStrategy::Definitions))->map(self::serialized($definition), $schemaProvider));

        self::assertSame('#/definitions/BasicObject', $serialized['properties']['related']['$ref']);
        self::assertSame('object', $serialized['definitions']['BasicObject']['type']);
        self::assertSame(['id'], $serialized['definitions']['BasicObject']['required']);
    }

    public function testMapCanReferenceEnumTypesThroughDefinitions(): void
    {
        $definition = new ClassDefinition('MyDto');

        $status = new PropertyDefinition('status');
        $status->setType(Types::enum(StatusEnum::class));
        $definition->addProperty($status);

        $schemaProvider = static fn(string $className): Schema => (new Schema())->setType(SchemaType::OBJECT);

        $serialized = self::serializeSchema((new StandardSchemaMapper(ClassReferenceStrategy::Definitions))->map(self::serialized($definition), $schemaProvider));

        self::assertSame('#/definitions/StatusEnum', $serialized['properties']['status']['$ref']);
        self::assertSame('string', $serialized['definitions']['StatusEnum']['type']);
        self::assertSame(['active', 'inactive'], $serialized['definitions']['StatusEnum']['enum']);
    }

    public function testMapCanInlineClassLikeAndEnumTypes(): void
    {
        $definition = new ClassDefinition('MyDto');

        $related = new PropertyDefinition('related');
        $related->setType(Types::classLike(BasicObject::class));
        $definition->addProperty($related);

        $status = new PropertyDefinition('status');
        $status->setType(Types::enum(StatusEnum::class));
        $definition->addProperty($status);

        $schemaProvider = static fn(string $className): Schema => (new Schema())
            ->setType(SchemaType::OBJECT)
            ->addProperty('id', (new Schema())->setType(SchemaType::INTEGER), true);

        $serialized = self::serializeSchema((new StandardSchemaMapper(ClassReferenceStrategy::Inline))->map(self::serialized($definition), $schemaProvider));

        self::assertSame('object', $serialized['properties']['related']['type']);
        self::assertSame('integer', $serialized['properties']['related']['properties']['id']['type']);
        self::assertSame(['id'], $serialized['properties']['related']['required']);
        self::assertSame('string', $serialized['properties']['status']['type']);
        self::assertSame(['active', 'inactive'], $serialized['properties']['status']['enum']);
        self::assertArrayNotHasKey('definitions', $serialized);
    }

    public function testMapHandlesDictionaryAndIntersectionTypes(): void
    {
        $definition = new ClassDefinition('MyDto');

        $anything = new PropertyDefinition('anything');
        $anything->setType(Types::mixed());
        $definition->addProperty($anything);

        $headers = new PropertyDefinition('headers');
        $headers->setType(Types::mapOf(Types::string()));
        $definition->addProperty($headers);

        $combined = new PropertyDefinition('combined');
        $combined->setType(Types::intersection(
            Types::object(),
            Types::decorated(Types::object(), annotations: new TypeAnnotations(title: 'Tagged object'))
        ));
        $definition->addProperty($combined);

        $schemaProvider = static fn(string $className): Schema => new Schema();

        $serialized = self::serializeSchema((new StandardSchemaMapper())->map(self::serialized($definition), $schemaProvider));

        self::assertEquals(new \stdClass(), $serialized['properties']['anything']);
        self::assertSame('object', $serialized['properties']['headers']['type']);
        self::assertSame('string', $serialized['properties']['headers']['additionalProperties']['type']);
        self::assertSame('object', $serialized['properties']['combined']['allOf'][0]['type']);
        self::assertSame('object', $serialized['properties']['combined']['allOf'][1]['type']);
        self::assertSame('Tagged object', $serialized['properties']['combined']['allOf'][1]['title']);
    }

    #[DataProvider('unionSemanticsProvider')]
    public function testMapUsesExplicitUnionSemantics(UnionSemantics $semantics, string $keyword): void
    {
        $definition = new ClassDefinition('MyDto');

        $value = new PropertyDefinition('value');
        $union = Types::union(
            Types::decorated(Types::string(), new TypeConstraints(pattern: '^A')),
            Types::decorated(Types::string(), new TypeConstraints(minLength: 3))
        );
        $union->semantics = $semantics;
        $value->setType($union);
        $definition->addProperty($value);

        $schemaProvider = static fn(string $className): Schema => new Schema();

        $serialized = self::serializeSchema((new StandardSchemaMapper())->map(self::serialized($definition), $schemaProvider));

        self::assertCount(2, $serialized['properties']['value'][$keyword]);
    }

    /**
     * @return iterable<string, array{UnionSemantics, string}>
     */
    public static function unionSemanticsProvider(): iterable
    {
        yield 'oneOf' => [UnionSemantics::OneOf, 'oneOf'];
        yield 'anyOf' => [UnionSemantics::AnyOf, 'anyOf'];
    }

    public function testMapFailsForUnsupportedBuiltinTypes(): void
    {
        $definition = new ClassDefinition('MyDto');

        $value = new PropertyDefinition('value');
        $value->setType(Types::builtin('resource'));
        $definition->addProperty($value);

        $schemaProvider = static fn(string $className): Schema => new Schema();

        $this->expectException(\LogicException::class);

        (new StandardSchemaMapper())->map(self::serialized($definition), $schemaProvider);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeSchema(Schema $schema): array
    {
        $serialized = $schema->jsonSerialize();
        self::assertIsArray($serialized);

        return $serialized;
    }

    private static function serialized(ClassDefinition $definition): SerializedObjectDefinition
    {
        $properties = [];
        foreach ($definition->getProperties() as $property) {
            $serializedProperty = self::serializedProperty($property);
            $properties[$serializedProperty->name] = $serializedProperty;
        }

        return new SerializedObjectDefinition(
            name: $definition->getClassName(),
            properties: $properties,
            title: $definition->getTitle(),
            description: $definition->getDescription()
        );
    }

    private static function serializedInlineObject(InlineObjectDefinition $definition): SerializedObjectDefinition
    {
        $properties = [];
        foreach ($definition->getProperties() as $property) {
            $serializedProperty = self::serializedProperty($property);
            $properties[$serializedProperty->name] = $serializedProperty;
        }

        return new SerializedObjectDefinition(
            properties: $properties,
            title: $definition->getTitle(),
            description: $definition->getDescription(),
            additionalProperties: $definition->getAdditionalProperties()
        );
    }

    private static function serializedProperty(FieldDefinitionInterface $property): SerializedPropertyDefinition
    {
        return new SerializedPropertyDefinition(
            name: $property->getName(),
            required: $property->isRequired(),
            type: self::serializedType($property->getType())
        );
    }

    private static function serializedType(?Type $type): ?Type
    {
        return match (true) {
            $type === null => null,
            $type instanceof InlineObjectType => new SerializedObjectType(self::serializedInlineObject($type->shape)),
            $type instanceof DecoratedType => new DecoratedType(
                self::serializedType($type->type) ?? $type->type,
                $type->constraints,
                $type->annotations
            ),
            $type instanceof ArrayType => new ArrayType(self::serializedType($type->type) ?? $type->type),
            $type instanceof MapType => new MapType(self::serializedType($type->type) ?? $type->type),
            $type instanceof UnionType => new UnionType(
                array_map(static fn(Type $inner): Type => self::serializedType($inner) ?? $inner, $type->types),
                $type->semantics
            ),
            $type instanceof IntersectionType => new IntersectionType(
                array_map(static fn(Type $inner): Type => self::serializedType($inner) ?? $inner, $type->types)
            ),
            default => $type,
        };
    }
}
