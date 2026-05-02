<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Support;

use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\FieldDefinitionInterface;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;

trait TypeTestHelperTrait
{
    protected function requireProperty(ClassDefinition|InlineObjectDefinition $shape, string $propertyName): FieldDefinitionInterface
    {
        $property = $shape->getProperty($propertyName);
        if ($property === null) {
            self::fail(\sprintf('Expected property "%s" to exist.', $propertyName));
        }

        return $property;
    }

    protected function requireType(?Type $expr, string $message): Type
    {
        if ($expr === null) {
            self::fail($message);
        }

        return $expr;
    }

    protected function unwrapDecorated(Type $expr): Type
    {
        while ($expr instanceof DecoratedType) {
            $expr = $expr->type;
        }

        return $expr;
    }

    protected function findFirstDecorated(?Type $expr): ?DecoratedType
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

    protected function findFirstNonNullDecorated(?Type $expr): ?DecoratedType
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
    protected function collectTypeNames(?Type $expr): array
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

    protected function assertBuiltin(Type $expr, string $name): void
    {
        $expr = $this->unwrapDecorated($expr);
        self::assertInstanceOf(BuiltinType::class, $expr);
        self::assertSame($name, $expr->name);
    }

    protected function assertArrayOf(Type $expr): ArrayType
    {
        $expr = $this->unwrapDecorated($expr);
        self::assertInstanceOf(ArrayType::class, $expr);
        return $expr;
    }

    protected function assertInlineObject(Type $expr): InlineObjectDefinition
    {
        $expr = $this->unwrapDecorated($expr);
        self::assertInstanceOf(InlineObjectType::class, $expr);
        return $expr->shape;
    }
}
