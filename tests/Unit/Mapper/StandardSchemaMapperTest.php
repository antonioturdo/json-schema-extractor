<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\InlineFieldDefinition;
use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;
use Zeusi\JsonSchemaGenerator\Definition\PropertyDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\Type;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionSemantics;
use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;
use Zeusi\JsonSchemaGenerator\Definition\TypeConstraints;
use Zeusi\JsonSchemaGenerator\JsonSchema\Schema;
use Zeusi\JsonSchemaGenerator\JsonSchema\SchemaType;
use Zeusi\JsonSchemaGenerator\Mapper\ClassReferenceStrategy;
use Zeusi\JsonSchemaGenerator\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\BasicObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\StatusEnum;

final class StandardSchemaMapperTest extends TestCase
{
    public function testMapUsesTypeExprWhenProvided(): void
    {
        $definition = new ClassDefinition('MyDto');

        $union = new PropertyDefinition('union');
        $union->setTypeExpr(Type::union(Type::string(), Type::null()));
        $definition->addProperty($union);

        $nestedUnion = new PropertyDefinition('nestedUnion');
        $nestedUnion->setTypeExpr(Type::union(
            Type::union(Type::string(), Type::bool()),
            Type::null()
        ));
        $definition->addProperty($nestedUnion);

        $decorated = new PropertyDefinition('decorated');
        $decorated->setTypeExpr(Type::decorated(
            Type::arrayOf(Type::decorated(
                Type::string(),
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
        $inlineObject->getProperty('foo')?->setTypeExpr(Type::string());

        $inline = new PropertyDefinition('inline');
        $inline->setTypeExpr(Type::inlineObject($inlineObject));
        $definition->addProperty($inline);

        $untyped = new PropertyDefinition('untyped');
        $definition->addProperty($untyped);

        $schemaProvider = static fn(string $className): Schema => (new Schema())->setType(SchemaType::OBJECT);

        $serialized = self::serializeSchema((new StandardSchemaMapper())->map($definition, $schemaProvider));

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

        self::assertEquals(new \stdClass(), $serialized['properties']['untyped']);
    }

    public function testMapCanReferenceClassLikeTypesThroughDefinitions(): void
    {
        $definition = new ClassDefinition('MyDto');

        $related = new PropertyDefinition('related');
        $related->setTypeExpr(Type::classLike(BasicObject::class));
        $definition->addProperty($related);

        $schemaProvider = static fn(string $className): Schema => (new Schema())
            ->setType(SchemaType::OBJECT)
            ->addProperty('id', (new Schema())->setType(SchemaType::INTEGER), true);

        $serialized = self::serializeSchema((new StandardSchemaMapper(ClassReferenceStrategy::Definitions))->map($definition, $schemaProvider));

        self::assertSame('#/definitions/BasicObject', $serialized['properties']['related']['$ref']);
        self::assertSame('object', $serialized['definitions']['BasicObject']['type']);
        self::assertSame(['id'], $serialized['definitions']['BasicObject']['required']);
    }

    public function testMapCanReferenceEnumTypesThroughDefinitions(): void
    {
        $definition = new ClassDefinition('MyDto');

        $status = new PropertyDefinition('status');
        $status->setTypeExpr(Type::enum(StatusEnum::class));
        $definition->addProperty($status);

        $schemaProvider = static fn(string $className): Schema => (new Schema())->setType(SchemaType::OBJECT);

        $serialized = self::serializeSchema((new StandardSchemaMapper(ClassReferenceStrategy::Definitions))->map($definition, $schemaProvider));

        self::assertSame('#/definitions/StatusEnum', $serialized['properties']['status']['$ref']);
        self::assertSame('string', $serialized['definitions']['StatusEnum']['type']);
        self::assertSame(['active', 'inactive'], $serialized['definitions']['StatusEnum']['enum']);
    }

    public function testMapCanInlineClassLikeAndEnumTypes(): void
    {
        $definition = new ClassDefinition('MyDto');

        $related = new PropertyDefinition('related');
        $related->setTypeExpr(Type::classLike(BasicObject::class));
        $definition->addProperty($related);

        $status = new PropertyDefinition('status');
        $status->setTypeExpr(Type::enum(StatusEnum::class));
        $definition->addProperty($status);

        $schemaProvider = static fn(string $className): Schema => (new Schema())
            ->setType(SchemaType::OBJECT)
            ->addProperty('id', (new Schema())->setType(SchemaType::INTEGER), true);

        $serialized = self::serializeSchema((new StandardSchemaMapper(ClassReferenceStrategy::Inline))->map($definition, $schemaProvider));

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
        $anything->setTypeExpr(Type::mixed());
        $definition->addProperty($anything);

        $headers = new PropertyDefinition('headers');
        $headers->setTypeExpr(Type::mapOf(Type::string()));
        $definition->addProperty($headers);

        $combined = new PropertyDefinition('combined');
        $combined->setTypeExpr(Type::intersection(
            Type::object(),
            Type::decorated(Type::object(), annotations: new TypeAnnotations(title: 'Tagged object'))
        ));
        $definition->addProperty($combined);

        $schemaProvider = static fn(string $className): Schema => new Schema();

        $serialized = self::serializeSchema((new StandardSchemaMapper())->map($definition, $schemaProvider));

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
        $union = Type::union(
            Type::decorated(Type::string(), new TypeConstraints(pattern: '^A')),
            Type::decorated(Type::string(), new TypeConstraints(minLength: 3))
        );
        $union->semantics = $semantics;
        $value->setTypeExpr($union);
        $definition->addProperty($value);

        $schemaProvider = static fn(string $className): Schema => new Schema();

        $serialized = self::serializeSchema((new StandardSchemaMapper())->map($definition, $schemaProvider));

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
        $value->setTypeExpr(Type::builtin('resource'));
        $definition->addProperty($value);

        $schemaProvider = static fn(string $className): Schema => new Schema();

        $this->expectException(\LogicException::class);

        (new StandardSchemaMapper())->map($definition, $schemaProvider);
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
}
