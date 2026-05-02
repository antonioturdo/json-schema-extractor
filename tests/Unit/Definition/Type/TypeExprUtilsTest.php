<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Definition\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\ClassLikeType;
use Zeusi\JsonSchemaGenerator\Definition\Type\DecoratedType;
use Zeusi\JsonSchemaGenerator\Definition\Type\IntersectionType;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExprUtils;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionType;
use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;
use Zeusi\JsonSchemaGenerator\Definition\TypeConstraints;

#[CoversClass(TypeExprUtils::class)]
final class TypeExprUtilsTest extends TestCase
{
    public function testNormalizeUnionFlattensDeduplicatesAndCollapsesSingleAlternatives(): void
    {
        $normalized = TypeExprUtils::normalizeUnion([
            new BuiltinType('string'),
            new UnionType([
                new BuiltinType('int'),
                new BuiltinType('string'),
            ]),
        ]);

        self::assertInstanceOf(UnionType::class, $normalized);
        self::assertSame(['string', 'int'], array_map(
            static fn(TypeExpr $type): string => self::builtinName($type),
            $normalized->types
        ));

        $collapsed = TypeExprUtils::normalizeUnion([
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

        $rewritten = TypeExprUtils::rewrite(
            $expr,
            static function (TypeExpr $type): ?TypeExpr {
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

        $decorated = TypeExprUtils::decorateNonNullBranches(
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

    private static function builtinName(TypeExpr $type): string
    {
        self::assertInstanceOf(BuiltinType::class, $type);

        return $type->name;
    }
}
