<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Model\Php;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\MethodDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\PropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\Types;

#[CoversClass(ClassDefinition::class)]
#[CoversClass(InlineObjectDefinition::class)]
#[CoversClass(MethodDefinition::class)]
class ClassDefinitionTest extends TestCase
{
    public function testAddPropertyStoresClassProperty(): void
    {
        $definition = new ClassDefinition('App\\Dto\\Example');
        $property = new PropertyDefinition('email');

        $definition->addProperty($property);

        self::assertSame($property, $definition->getProperty('email'));
        self::assertSame(['email' => $property], $definition->getProperties());
    }

    public function testGetOrCreatePropertyReturnsExistingInlineObjectPropertyWithoutReplacingIt(): void
    {
        $existingProperty = new InlineFieldDefinition('email');
        $definition = new InlineObjectDefinition('shape', properties: [
            'email' => $existingProperty,
        ]);

        $newProperty = new InlineFieldDefinition('email');
        $resolvedProperty = $definition->getOrCreateProperty($newProperty);

        self::assertSame($existingProperty, $resolvedProperty);
        self::assertSame($existingProperty, $definition->getProperty('email'));
    }

    public function testAddMethodStoresClassMethod(): void
    {
        $definition = new ClassDefinition('App\\Dto\\Example');
        $method = new MethodDefinition('jsonSerialize', Types::array());

        $definition->addMethod($method);

        self::assertSame($method, $definition->getMethod('jsonSerialize'));
        self::assertSame(['jsonSerialize' => $method], $definition->getMethods());
    }
}
