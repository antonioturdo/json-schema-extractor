<?php

namespace Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor;

use phpDocumentor\Reflection\PseudoTypes\False_;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyArray;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyList;
use phpDocumentor\Reflection\PseudoTypes\True_;
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

final class PhpDocumentorTypeMapperV5 extends AbstractPhpDocumentorTypeMapper
{
    private const ARRAY_KEY_TYPE = 'phpDocumentor\\Reflection\\Types\\ArrayKey';
    private const COLLECTION_TYPE = 'phpDocumentor\\Reflection\\Types\\Collection';
    private const INTERFACE_STRING_TYPE = 'phpDocumentor\\Reflection\\Types\\InterfaceString';
    private const SCALAR_TYPE = 'phpDocumentor\\Reflection\\Types\\Scalar';

    protected function parseVersionSpecificLiteralType(PhpDocumentorType $type): ?Type
    {
        if ($type instanceof True_) {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [true]));
        }

        if ($type instanceof False_) {
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

        if ($type instanceof Array_ || $type instanceof Iterable_ || $type::class === self::COLLECTION_TYPE) {
            return $this->mapListLikeType($type, $context);
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapListLikeType(PhpDocumentorType $type, \ReflectionClass $context): Type
    {
        if (!method_exists($type, 'getKeyType') || !method_exists($type, 'getValueType')) {
            return new ArrayType(new BuiltinType('mixed'));
        }

        $keyType = $type->getKeyType();
        $valueType = $type->getValueType();
        $valueExpr = $this->parse($valueType, $context);

        if ((string) $keyType === 'string') {
            return new MapType($valueExpr);
        }

        return new ArrayType($valueExpr);
    }
}
