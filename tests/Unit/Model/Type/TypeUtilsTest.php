<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Model\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeUtils;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;

#[CoversClass(TypeUtils::class)]
final class TypeUtilsTest extends TestCase
{
    public function testNormalizeUnionFlattensDeduplicatesAndCollapsesSingleAlternatives(): void
    {
        $normalized = TypeUtils::normalizeUnion([
            new BuiltinType('string'),
            new UnionType([
                new BuiltinType('int'),
                new BuiltinType('string'),
            ]),
        ]);

        self::assertInstanceOf(UnionType::class, $normalized);
        self::assertSame(['string', 'int'], array_map(
            static fn(Type $type): string => self::builtinName($type),
            $normalized->types
        ));

        $collapsed = TypeUtils::normalizeUnion([
            new BuiltinType('string'),
            new BuiltinType('string'),
        ]);

        self::assertInstanceOf(BuiltinType::class, $collapsed);
        self::assertSame('string', $collapsed->name);
    }

    public function testRewriteRecursesAndFlattensNestedDecorations(): void
    {
        $expr = new ArrayType(
            new DecoratedType(
                new ClassLikeType(\DateTimeInterface::class),
                new TypeConstraints(minLength: 1),
                new TypeAnnotations(title: 'outer title')
            )
        );

        $rewritten = TypeUtils::rewrite(
            $expr,
            static function (Type $type): ?Type {
                if (!$type instanceof ClassLikeType || $type->name !== \DateTimeInterface::class) {
                    return null;
                }

                return new DecoratedType(
                    new BuiltinType('string'),
                    new TypeConstraints(maxLength: 30),
                    new TypeAnnotations(title: 'inner title', format: 'date-time')
                );
            }
        );

        self::assertInstanceOf(ArrayType::class, $rewritten);

        $itemType = $rewritten->type;
        self::assertInstanceOf(DecoratedType::class, $itemType);

        $innerType = $itemType->type;
        self::assertInstanceOf(BuiltinType::class, $innerType);

        self::assertSame('string', $innerType->name);
        self::assertSame(1, $itemType->constraints->minLength);
        self::assertSame(30, $itemType->constraints->maxLength);
        self::assertNotNull($itemType->annotations);
        self::assertSame('outer title', $itemType->annotations->title);
        self::assertSame('date-time', $itemType->annotations->format);
    }

    public function testDecorateNonNullBranchesSkipsNullAndDecoratesIntersectionAsSingleBranch(): void
    {
        $expr = new UnionType([
            new BuiltinType('string'),
            new BuiltinType('null'),
            new IntersectionType([
                new ClassLikeType(\Stringable::class),
                new ClassLikeType(\Countable::class),
            ]),
        ]);

        $decorated = TypeUtils::decorateNonNullBranches(
            $expr,
            static function (DecoratedType $type): void {
                $type->constraints->enum = ['value'];
            }
        );

        self::assertInstanceOf(UnionType::class, $decorated);
        self::assertCount(3, $decorated->types);

        $stringBranch = $decorated->types[0];
        self::assertInstanceOf(DecoratedType::class, $stringBranch);
        self::assertSame(['value'], $stringBranch->constraints->enum);

        $stringType = $stringBranch->type;
        self::assertInstanceOf(BuiltinType::class, $stringType);
        self::assertSame('string', $stringType->name);

        $nullBranch = $decorated->types[1];
        self::assertInstanceOf(BuiltinType::class, $nullBranch);
        self::assertSame('null', $nullBranch->name);

        $intersectionBranch = $decorated->types[2];
        self::assertInstanceOf(DecoratedType::class, $intersectionBranch);
        self::assertSame(['value'], $intersectionBranch->constraints->enum);
        self::assertInstanceOf(IntersectionType::class, $intersectionBranch->type);
    }

    public function testNullHelpersDetectAndPreserveNullableBranches(): void
    {
        self::assertFalse(TypeUtils::allowsNull(new BuiltinType('string')));
        self::assertTrue(TypeUtils::allowsNull(new BuiltinType('null')));
        self::assertTrue(TypeUtils::allowsNull(new DecoratedType(new UnionType([
            new BuiltinType('string'),
            new BuiltinType('null'),
        ]))));

        self::assertSame('string', self::builtinName(TypeUtils::preserveNullableUnion(null, new BuiltinType('string'))));
        self::assertSame('int', self::builtinName(TypeUtils::preserveNullableUnion(
            new BuiltinType('string'),
            new BuiltinType('int')
        )));

        $preserved = TypeUtils::preserveNullableUnion(
            new UnionType([
                new BuiltinType('string'),
                new BuiltinType('null'),
            ]),
            new BuiltinType('int')
        );

        self::assertInstanceOf(UnionType::class, $preserved);
        self::assertSame(['null', 'int'], array_map(
            static fn(Type $type): string => self::builtinName($type),
            $preserved->types
        ));
    }

    public function testMergeTypeMetadataPreservesExistingValuesAndDeduplicatesExamples(): void
    {
        $current = new DecoratedType(
            new BuiltinType('string'),
            new TypeConstraints(minimum: 1, minLength: 2, pattern: '^a'),
            new TypeAnnotations(title: 'Current title', deprecated: true, examples: ['one'])
        );
        $next = new DecoratedType(
            new BuiltinType('string'),
            new TypeConstraints(maximum: 9, maxLength: 10),
            new TypeAnnotations(description: 'Next description', examples: ['one', 'two'])
        );

        $merged = TypeUtils::mergeTypeConstraintsAndAnnotations($current, $next);

        self::assertInstanceOf(DecoratedType::class, $merged);
        self::assertSame(1, $merged->constraints->minimum);
        self::assertSame(9, $merged->constraints->maximum);
        self::assertSame(2, $merged->constraints->minLength);
        self::assertSame(10, $merged->constraints->maxLength);
        self::assertSame('^a', $merged->constraints->pattern);
        $annotations = $merged->annotations;
        self::assertNotNull($annotations);
        self::assertSame('Current title', $annotations->title);
        self::assertSame('Next description', $annotations->description);
        self::assertTrue($annotations->deprecated);
        self::assertSame(['one', 'two'], $annotations->examples);
        self::assertSame(['one', ['nested' => true]], TypeUtils::mergeExamples(
            ['one'],
            ['one', ['nested' => true]]
        ));
    }

    private static function builtinName(Type $type): string
    {
        self::assertInstanceOf(BuiltinType::class, $type);

        return $type->name;
    }
}
