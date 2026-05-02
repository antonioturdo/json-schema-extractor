<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Support;

use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\ClassLikeType;
use Zeusi\JsonSchemaGenerator\Definition\Type\DecoratedType;
use Zeusi\JsonSchemaGenerator\Definition\Type\EnumType;
use Zeusi\JsonSchemaGenerator\Definition\Type\InlineObjectType;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionType;

trait TypeExprTestHelperTrait
{
    protected function requireTypeExpr(?TypeExpr $expr, string $message): TypeExpr
    {
        if ($expr === null) {
            self::fail($message);
        }

        return $expr;
    }

    protected function unwrapDecorated(TypeExpr $expr): TypeExpr
    {
        while ($expr instanceof DecoratedType) {
            $expr = $expr->type;
        }

        return $expr;
    }

    protected function findFirstDecorated(?TypeExpr $expr): ?DecoratedType
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof DecoratedType) {
            return $expr;
        }

        if ($expr instanceof UnionType) {
            foreach ($expr->types as $subType) {
                $found = $this->findFirstDecorated($subType);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    protected function findFirstNonNullDecorated(?TypeExpr $expr): ?DecoratedType
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof DecoratedType) {
            $inner = $this->unwrapDecorated($expr->type);
            if ($inner instanceof BuiltinType && $inner->name === 'null') {
                return null;
            }

            return $expr;
        }

        if ($expr instanceof UnionType) {
            foreach ($expr->types as $subType) {
                $found = $this->findFirstNonNullDecorated($subType);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function collectTypeNames(?TypeExpr $expr): array
    {
        if ($expr === null) {
            return [];
        }

        $expr = $this->unwrapDecorated($expr);

        if ($expr instanceof UnionType) {
            $names = [];
            foreach ($expr->types as $subType) {
                foreach ($this->collectTypeNames($subType) as $name) {
                    $names[] = $name;
                }
            }

            return array_values(array_unique($names));
        }

        if ($expr instanceof BuiltinType) {
            return [$expr->name];
        }

        if ($expr instanceof ClassLikeType) {
            return [$expr->name];
        }

        if ($expr instanceof EnumType) {
            return [$expr->className];
        }

        return [];
    }

    protected function assertBuiltin(TypeExpr $expr, string $name): void
    {
        $expr = $this->unwrapDecorated($expr);
        self::assertInstanceOf(BuiltinType::class, $expr);
        self::assertSame($name, $expr->name);
    }

    protected function assertArrayOf(TypeExpr $expr): ArrayType
    {
        $expr = $this->unwrapDecorated($expr);
        self::assertInstanceOf(ArrayType::class, $expr);
        return $expr;
    }

    protected function assertInlineObject(TypeExpr $expr): InlineObjectDefinition
    {
        $expr = $this->unwrapDecorated($expr);
        self::assertInstanceOf(InlineObjectType::class, $expr);
        return $expr->shape;
    }
}
