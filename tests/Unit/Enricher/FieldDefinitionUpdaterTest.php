<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\FieldDefinitionUpdater;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\Types;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(FieldDefinitionUpdater::class)]
final class FieldDefinitionUpdaterTest extends TestCase
{
    use TypeTestHelperTrait;

    public function testApplyCompatibleDeclaredTypeRefinesCompatibleUnionBranchAndPreservesOthers(): void
    {
        $field = new InlineFieldDefinition('value');
        $field->setType(Types::union(Types::array(), Types::int()));

        $inlineObject = new InlineObjectDefinition('shape');
        $inlineObject->addProperty(new InlineFieldDefinition('email', required: true));

        $updater = new FieldDefinitionUpdater();
        $updater->applyCompatibleDeclaredType($field, Types::inlineObject($inlineObject));

        $type = $this->requireType($field->getType(), 'Expected value to have a type.');
        $typeNames = $this->collectTypeNames($type);

        self::assertSame(['int'], $typeNames);
        $type = $this->unwrapDecorated($type);
        self::assertInstanceOf(UnionType::class, $type);
        self::assertCount(2, $type->types);
        foreach ($type->types as $branch) {
            if ($this->unwrapDecorated($branch) instanceof \Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType) {
                $this->assertInlineObject($branch);
                return;
            }
        }

        self::fail('Expected the refined union to keep the inline object branch.');
    }

    public function testApplyCompatibleDeclaredTypeRejectsUnionWhenDeclaredTypeIntroducesAnIncompatibleBranch(): void
    {
        $field = new InlineFieldDefinition('value');
        $currentType = Types::union(Types::array(), Types::int());
        $field->setType($currentType);

        $inlineObject = new InlineObjectDefinition('shape');
        $inlineObject->addProperty(new InlineFieldDefinition('email', required: true));

        $updater = new FieldDefinitionUpdater();
        $updater->applyCompatibleDeclaredType(
            $field,
            Types::union(
                Types::inlineObject($inlineObject),
                Types::string(),
            ),
        );

        self::assertSame($currentType, $field->getType());
    }

    public function testApplyCompatibleDeclaredTypePreservesNullableUnionBranchWhenRefiningOnlyTheNonNullType(): void
    {
        $field = new InlineFieldDefinition('value');
        $field->setType(Types::union(Types::string(), Types::null()));

        $updater = new FieldDefinitionUpdater();
        $updater->applyCompatibleDeclaredType($field, Types::string());

        self::assertSame(['string', 'null'], $this->collectTypeNames($field->getType()));
    }

    public function testAppliesPresenceAnnotationsAndConstraintsToNonNullBranches(): void
    {
        $field = new InlineFieldDefinition('value');
        $field->setType(Types::union(Types::string(), Types::null()));

        $updater = new FieldDefinitionUpdater();
        $updater->markRequired($field);
        $updater->applyTitle($field, 'Title');
        $updater->applyTitle($field, 'Ignored title');
        $updater->applyDescription($field, 'Description');
        $updater->applyDeprecated($field);
        $updater->applyExamples($field, ['one', 'one']);
        $updater->applyExamples($field, ['two']);
        $updater->applyFormat($field, 'email');
        $updater->applyPattern($field, '^.+@example\.com$');
        $updater->applyLength($field, 3, 50);
        $updater->applyRange($field, 1, 99);
        $updater->applyMinimum($field, 2);
        $updater->applyMaximum($field, 98);
        $updater->applyExclusiveMinimum($field, 1);
        $updater->applyExclusiveMaximum($field, 99);
        $updater->applyMultipleOf($field, 2);
        $updater->applyItemCount($field, 1, 3);
        $updater->applyEnum($field, ['one', 'two']);

        self::assertTrue($field->isRequired());

        $decorated = $this->findFirstNonNullDecorated($field->getType());
        self::assertNotNull($decorated);
        $annotations = $decorated->annotations;
        self::assertNotNull($annotations);
        self::assertSame('Title', $annotations->title);
        self::assertSame('Description', $annotations->description);
        self::assertTrue($annotations->deprecated);
        self::assertSame(['one', 'two'], $annotations->examples);
        self::assertSame('email', $annotations->format);

        self::assertSame(['one', 'two'], $decorated->constraints->enum);
        self::assertSame(2, $decorated->constraints->minimum);
        self::assertSame(98, $decorated->constraints->maximum);
        self::assertSame(1, $decorated->constraints->exclusiveMinimum);
        self::assertSame(99, $decorated->constraints->exclusiveMaximum);
        self::assertSame(2, $decorated->constraints->multipleOf);
        self::assertSame(3, $decorated->constraints->minLength);
        self::assertSame(50, $decorated->constraints->maxLength);
        self::assertSame('^.+@example\.com$', $decorated->constraints->pattern);
        self::assertSame(1, $decorated->constraints->minItems);
        self::assertSame(3, $decorated->constraints->maxItems);
        self::assertSame(['string', 'null'], $this->collectTypeNames($field->getType()));
    }

    public function testTransformArrayItemsAppliesChangesInsideArraysAndUnions(): void
    {
        $field = new InlineFieldDefinition('values');
        $field->setType(Types::union(
            Types::arrayOf(Types::string()),
            Types::null(),
        ));

        $updater = new FieldDefinitionUpdater();
        $updater->transformArrayItems(
            $field,
            static function (InlineFieldDefinition $items) use ($updater): void {
                $updater->applyFormat($items, 'email');
            }
        );

        $type = $this->requireType($field->getType(), 'Expected values to have a type.');
        self::assertInstanceOf(UnionType::class, $type);
        self::assertInstanceOf(ArrayType::class, $type->types[0]);
        self::assertInstanceOf(DecoratedType::class, $type->types[0]->type);
        self::assertSame('email', $type->types[0]->type->annotations?->format);
        $this->assertBuiltin($type->types[1], 'null');
    }
}
