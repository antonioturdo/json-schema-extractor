<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Serialization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\JsonEncodeContext;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\PropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\Types;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(JsonEncodeSerializationStrategy::class)]
final class JsonEncodeSerializationStrategyTest extends TestCase
{
    use TypeTestHelperTrait;

    public function testProjectCopiesClassMetadataAndProjectsKnownJsonEncodeShapes(): void
    {
        $definition = new ClassDefinition(
            className: 'App\\Payload',
            title: 'Payload',
            description: 'Serializable payload'
        );

        $name = new PropertyDefinition('name', required: true);
        $name->setType(Types::string());
        $definition->addProperty($name);

        $preferences = new PropertyDefinition('preferences');
        $preferences->setType(new DecoratedType(
            Types::array(),
            annotations: new TypeAnnotations(default: [])
        ));
        $definition->addProperty($preferences);

        $createdAt = new PropertyDefinition('createdAt', required: true);
        $createdAt->setType(new ClassLikeType(\DateTimeImmutable::class));
        $definition->addProperty($createdAt);

        $inlineObject = new InlineObjectDefinition(
            id: 'preferences',
            title: 'Preferences',
            description: 'User preferences',
            additionalProperties: false
        );
        $email = new InlineFieldDefinition('email', required: true);
        $email->setType(Types::string());
        $inlineObject->addProperty($email);

        $shape = new PropertyDefinition('shape');
        $shape->setType(new InlineObjectType($inlineObject));
        $definition->addProperty($shape);

        $context = (new ExtractionContext())->with(new JsonEncodeContext(\JSON_FORCE_OBJECT));
        $projected = (new JsonEncodeSerializationStrategy())->project($definition, $context);

        self::assertSame('App\\Payload', $projected->name);
        self::assertSame('Payload', $projected->title);
        self::assertSame('Serializable payload', $projected->description);

        $nameProperty = $this->requireSerializedProperty($projected, 'name');
        self::assertTrue($nameProperty->required);
        $this->assertBuiltin($this->requireType($nameProperty->type, 'Expected name to have a type.'), 'string');

        $preferencesDecorated = $this->findFirstNonNullDecorated($this->requireSerializedProperty($projected, 'preferences')->type);
        self::assertNotNull($preferencesDecorated);
        self::assertEquals(new \stdClass(), $preferencesDecorated->annotations?->default);

        $this->assertDateTimeShape($this->requireSerializedProperty($projected, 'createdAt'));
        $this->assertInlineObjectShape($this->requireSerializedProperty($projected, 'shape'));
    }

    public function testProjectRecursivelyCopiesNestedTypes(): void
    {
        $definition = new ClassDefinition(className: 'App\\Payload');

        $events = new PropertyDefinition('events');
        $events->setType(new UnionType([
            new ArrayType(new ClassLikeType(\DateTimeImmutable::class)),
            Types::null(),
        ]));
        $definition->addProperty($events);

        $projected = (new JsonEncodeSerializationStrategy())->project($definition, new ExtractionContext());

        $eventsType = $this->requireType(
            $this->requireSerializedProperty($projected, 'events')->type,
            'Expected events to have a type.'
        );
        self::assertInstanceOf(UnionType::class, $eventsType);
        self::assertCount(2, $eventsType->types);

        self::assertInstanceOf(ArrayType::class, $eventsType->types[0]);
        $itemType = $eventsType->types[0]->type;
        self::assertInstanceOf(SerializedObjectType::class, $itemType);
        $this->assertBuiltin(
            $this->requireType($this->requireSerializedProperty($itemType->shape, 'date')->type, 'Expected date to have a type.'),
            'string'
        );

        $this->assertBuiltin($eventsType->types[1], 'null');
    }

    private function assertDateTimeShape(SerializedPropertyDefinition $property): void
    {
        self::assertTrue($property->required);

        $type = $this->requireType($property->type, 'Expected datetime to have a type.');
        self::assertInstanceOf(SerializedObjectType::class, $type);
        self::assertFalse($type->shape->additionalProperties);

        $date = $this->requireSerializedProperty($type->shape, 'date');
        self::assertTrue($date->required);
        $dateType = $this->findFirstNonNullDecorated($date->type);
        self::assertNotNull($dateType);
        $this->assertBuiltin($dateType->type, 'string');
        self::assertSame('^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$', $dateType->constraints->pattern);

        $timezoneType = $this->requireSerializedProperty($type->shape, 'timezone_type');
        self::assertTrue($timezoneType->required);
        $timezoneTypeExpr = $this->findFirstNonNullDecorated($timezoneType->type);
        self::assertNotNull($timezoneTypeExpr);
        $this->assertBuiltin($timezoneTypeExpr->type, 'int');
        self::assertSame([1, 2, 3], $timezoneTypeExpr->constraints->enum);

        $timezone = $this->requireSerializedProperty($type->shape, 'timezone');
        self::assertTrue($timezone->required);
        $this->assertBuiltin($this->requireType($timezone->type, 'Expected timezone to have a type.'), 'string');
    }

    private function assertInlineObjectShape(SerializedPropertyDefinition $property): void
    {
        $type = $this->requireType($property->type, 'Expected shape to have a type.');
        self::assertInstanceOf(SerializedObjectType::class, $type);

        self::assertNull($type->shape->name);
        self::assertSame('Preferences', $type->shape->title);
        self::assertSame('User preferences', $type->shape->description);
        self::assertFalse($type->shape->additionalProperties);

        $email = $this->requireSerializedProperty($type->shape, 'email');
        self::assertTrue($email->required);
        $this->assertBuiltin($this->requireType($email->type, 'Expected email to have a type.'), 'string');
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
