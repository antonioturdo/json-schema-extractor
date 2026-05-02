<?php

namespace Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor;

use phpDocumentor\Reflection\PseudoTypes\NonEmptyArray;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyList;
use phpDocumentor\Reflection\Type as PhpDocumentorType;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Iterable_;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;

final class PhpDocumentorTypeMapperV6 extends AbstractPhpDocumentorTypeMapper
{
    private const ARRAY_KEY_TYPE = 'phpDocumentor\\Reflection\\PseudoTypes\\ArrayKey';
    private const FALSE_TYPE = 'phpDocumentor\\Reflection\\PseudoTypes\\False_';
    private const GENERIC_TYPE = 'phpDocumentor\\Reflection\\PseudoTypes\\Generic';
    private const INTERFACE_STRING_TYPE = 'phpDocumentor\\Reflection\\PseudoTypes\\InterfaceString';
    private const SCALAR_TYPE = 'phpDocumentor\\Reflection\\PseudoTypes\\Scalar';
    private const TRUE_TYPE = 'phpDocumentor\\Reflection\\PseudoTypes\\True_';

    protected function parseVersionSpecificLiteralType(PhpDocumentorType $type): ?Type
    {
        if ($type::class === self::TRUE_TYPE) {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [true]));
        }

        if ($type::class === self::FALSE_TYPE) {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [false]));
        }

        return null;
    }

    protected function parseVersionSpecificStringType(PhpDocumentorType $type): ?Type
    {
        if ($type::class === self::INTERFACE_STRING_TYPE) {
            return new BuiltinType('string');
        }

        return null;
    }

    protected function parseVersionSpecificScalarType(PhpDocumentorType $type): ?Type
    {
        if ($type::class === self::ARRAY_KEY_TYPE) {
            return new UnionType([new BuiltinType('string'), new BuiltinType('int')]);
        }

        if ($type::class === self::SCALAR_TYPE) {
            return $this->scalarUnionType();
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    protected function parseCollectionType(PhpDocumentorType $type, \ReflectionClass $context): ?Type
    {
        if ($type instanceof NonEmptyList) {
            return new DecoratedType($this->mapListLikeType($type, $context), new TypeConstraints(minItems: 1));
        }

        if ($type instanceof NonEmptyArray) {
            return new DecoratedType($this->mapListLikeType($type, $context), new TypeConstraints(minItems: 1));
        }

        if ($type instanceof Array_ || $type instanceof Iterable_) {
            return $this->mapListLikeType($type, $context);
        }

        if ($this->isGeneric($type)) {
            return $this->mapGenericType($type, $context);
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapListLikeType(Array_|Iterable_|NonEmptyArray|NonEmptyList $type, \ReflectionClass $context): Type
    {
        $keyType = $type->getKeyType();
        $valueType = $type->getValueType();
        $valueExpr = $this->parse($valueType, $context);

        if ((string) $keyType === 'string') {
            return new MapType($valueExpr);
        }

        return new ArrayType($valueExpr);
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapGenericType(PhpDocumentorType $type, \ReflectionClass $context): Type
    {
        $fqsen = $this->genericFqsen($type);
        $types = $this->genericTypes($type);
        $genericName = strtolower(ltrim($fqsen ?? '', '\\'));

        if ($this->isGenericArrayLike($genericName)) {
            $keyType = \count($types) > 1 ? $types[0] : null;
            $valueType = \count($types) > 1 ? $types[1] : ($types[0] ?? null);
            $valueExpr = $valueType instanceof PhpDocumentorType ? $this->parse($valueType, $context) : new BuiltinType('mixed');

            if ($keyType !== null && (string) $keyType === 'string') {
                return new MapType($valueExpr);
            }

            return new ArrayType($valueExpr);
        }

        return $this->mapClassLikeName($fqsen);
    }

    private function isGeneric(PhpDocumentorType $type): bool
    {
        return $type::class === self::GENERIC_TYPE;
    }

    private function genericFqsen(PhpDocumentorType $type): ?string
    {
        if (!method_exists($type, 'getFqsen')) {
            return null;
        }

        $fqsen = $type->getFqsen();

        return $fqsen === null ? null : (string) $fqsen;
    }

    /**
     * @return array<int|string, PhpDocumentorType>
     */
    private function genericTypes(PhpDocumentorType $type): array
    {
        if (!method_exists($type, 'getTypes')) {
            return [];
        }

        $types = $type->getTypes();
        if (!\is_array($types)) {
            return [];
        }

        return array_filter($types, static fn(mixed $innerType): bool => $innerType instanceof PhpDocumentorType);
    }

    private function isGenericArrayLike(string $genericName): bool
    {
        return \in_array($genericName, ['array', 'iterable', 'list', 'non-empty-array', 'non-empty-list'], true);
    }
}
