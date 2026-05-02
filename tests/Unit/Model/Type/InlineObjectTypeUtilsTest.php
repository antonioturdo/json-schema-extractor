<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Model\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectTypeUtils;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\Types;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(InlineObjectTypeUtils::class)]
final class InlineObjectTypeUtilsTest extends TestCase
{
    use TypeTestHelperTrait;

    public function testMergeInlineObjectTypesCombinesObjectAndFieldMetadata(): void
    {
        $current = new InlineObjectDefinition(
            id: 'shape',
            title: 'Current title',
            additionalProperties: false
        );
        $currentName = new InlineFieldDefinition('name');
        $currentName->setType(new DecoratedType(
            Types::string(),
            new TypeConstraints(minLength: 2),
            new TypeAnnotations(title: 'Name')
        ));
        $current->addProperty($currentName);

        $next = new InlineObjectDefinition(
            id: 'shape',
            title: 'Ignored title',
            description: 'Next description',
            additionalProperties: true
        );
        $nextName = new InlineFieldDefinition('name', required: true);
        $nextName->setType(new DecoratedType(
            Types::unknown(),
            new TypeConstraints(maxLength: 50),
            new TypeAnnotations(description: 'Readable name')
        ));
        $next->addProperty($nextName);

        $nextAge = new InlineFieldDefinition('age', required: true);
        $nextAge->setType(Types::int());
        $next->addProperty($nextAge);

        $merged = InlineObjectTypeUtils::mergeInlineObjectTypes(
            Types::inlineObject($current),
            Types::inlineObject($next)
        );

        self::assertInstanceOf(InlineObjectType::class, $merged);
        self::assertSame('Current title', $merged->shape->getTitle());
        self::assertSame('Next description', $merged->shape->getDescription());
        self::assertFalse($merged->shape->getAdditionalProperties());

        $name = $merged->shape->getProperty('name');
        self::assertNotNull($name);
        self::assertTrue($name->isRequired());

        $nameType = $this->findFirstNonNullDecorated($name->getType());
        self::assertNotNull($nameType);
        $this->assertBuiltin($nameType->type, 'string');
        self::assertSame(2, $nameType->constraints->minLength);
        self::assertSame(50, $nameType->constraints->maxLength);
        $annotations = $nameType->annotations;
        self::assertNotNull($annotations);
        self::assertSame('Name', $annotations->title);
        self::assertSame('Readable name', $annotations->description);

        $age = $merged->shape->getProperty('age');
        self::assertNotNull($age);
        self::assertTrue($age->isRequired());
        $this->assertBuiltin($this->requireType($age->getType(), 'Expected age to have a type.'), 'int');
    }

    public function testMergeInlineObjectTypesReturnsNextTypeWhenCurrentIsNotInlineObject(): void
    {
        $next = Types::string();

        self::assertSame($next, InlineObjectTypeUtils::mergeInlineObjectTypes(Types::int(), $next));
    }
}
