<?php

namespace Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor;

use phpDocumentor\Reflection\PseudoTypes\ArrayShape;
use phpDocumentor\Reflection\PseudoTypes\FloatValue;
use phpDocumentor\Reflection\PseudoTypes\IntegerRange;
use phpDocumentor\Reflection\PseudoTypes\IntegerValue;
use phpDocumentor\Reflection\PseudoTypes\NegativeInteger;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyLowercaseString;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyString;
use phpDocumentor\Reflection\PseudoTypes\Numeric_;
use phpDocumentor\Reflection\PseudoTypes\NumericString;
use phpDocumentor\Reflection\PseudoTypes\ObjectShape;
use phpDocumentor\Reflection\PseudoTypes\PositiveInteger;
use phpDocumentor\Reflection\PseudoTypes\ShapeItem;
use phpDocumentor\Reflection\PseudoTypes\StringValue;
use phpDocumentor\Reflection\Type as PhpDocumentorType;
use phpDocumentor\Reflection\Types\Boolean;
use phpDocumentor\Reflection\Types\Callable_;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Expression;
use phpDocumentor\Reflection\Types\Float_;
use phpDocumentor\Reflection\Types\Integer;
use phpDocumentor\Reflection\Types\Intersection;
use phpDocumentor\Reflection\Types\Mixed_;
use phpDocumentor\Reflection\Types\Never_;
use phpDocumentor\Reflection\Types\Null_;
use phpDocumentor\Reflection\Types\Nullable;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\Parent_;
use phpDocumentor\Reflection\Types\Resource_;
use phpDocumentor\Reflection\Types\Self_;
use phpDocumentor\Reflection\Types\Static_;
use phpDocumentor\Reflection\Types\String_;
use phpDocumentor\Reflection\Types\This;
use phpDocumentor\Reflection\Types\Void_;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeUtils;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;

abstract class AbstractPhpDocumentorTypeMapper implements PhpDocumentorTypeMapperInterface
{
    /**
     * @template TObject of object
     *
     * @param \ReflectionClass<TObject> $context
     */
    final public function parse(PhpDocumentorType $type, \ReflectionClass $context): Type
    {
        $expr = $this->parseCompositeType($type, $context)
            ?? $this->parseLiteralType($type)
            ?? $this->parseIntegerType($type)
            ?? $this->parseStringType($type)
            ?? $this->parseBooleanType($type)
            ?? $this->parseScalarType($type)
            ?? $this->parseShapeType($type, $context)
            ?? $this->parseCollectionType($type, $context)
            ?? $this->parseObjectType($type, $context);

        return $expr ?? new BuiltinType('mixed');
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function parseCompositeType(PhpDocumentorType $type, \ReflectionClass $context): ?Type
    {
        if ($type instanceof Expression) {
            return $this->parse($type->getValueType(), $context);
        }

        if ($type instanceof Compound) {
            $types = [];
            foreach ($type as $subType) {
                $types[] = $this->parse($subType, $context);
            }

            return TypeUtils::normalizeUnion($types);
        }

        if ($type instanceof Intersection) {
            $types = [];
            foreach ($type as $subType) {
                $types[] = $this->parse($subType, $context);
            }

            return new IntersectionType($types);
        }

        if ($type instanceof Nullable) {
            return TypeUtils::normalizeUnion([
                $this->parse($type->getActualType(), $context),
                new BuiltinType('null'),
            ]);
        }

        return null;
    }

    private function parseLiteralType(PhpDocumentorType $type): ?Type
    {
        if ($type instanceof StringValue) {
            return new DecoratedType(new BuiltinType('string'), new TypeConstraints(enum: [$type->getValue()]));
        }

        if ($type instanceof IntegerValue) {
            return new DecoratedType(new BuiltinType('int'), new TypeConstraints(enum: [$type->getValue()]));
        }

        if ($type instanceof FloatValue) {
            return new DecoratedType(new BuiltinType('float'), new TypeConstraints(enum: [$type->getValue()]));
        }

        return $this->parseVersionSpecificLiteralType($type);
    }

    private function parseIntegerType(PhpDocumentorType $type): ?Type
    {
        if ($type instanceof IntegerRange) {
            $min = $type->getMinValue();
            $max = $type->getMaxValue();

            $constraints = new TypeConstraints();
            if ($min !== 'min' && $min !== '') {
                $constraints->minimum = (int) $min;
            }
            if ($max !== 'max' && $max !== '') {
                $constraints->maximum = (int) $max;
            }

            return new DecoratedType(new BuiltinType('int'), $constraints);
        }

        if ($type instanceof PositiveInteger) {
            return new DecoratedType(new BuiltinType('int'), new TypeConstraints(minimum: 1));
        }

        if ($type instanceof NegativeInteger) {
            return new DecoratedType(new BuiltinType('int'), new TypeConstraints(maximum: -1));
        }

        if ($type instanceof Integer) {
            return new BuiltinType('int');
        }

        return null;
    }

    private function parseStringType(PhpDocumentorType $type): ?Type
    {
        if ($type instanceof NonEmptyLowercaseString) {
            return new DecoratedType(new BuiltinType('string'), new TypeConstraints(minLength: 1, pattern: '^[^A-Z]*$'));
        }

        if ($type instanceof NonEmptyString) {
            return new DecoratedType(new BuiltinType('string'), new TypeConstraints(minLength: 1));
        }

        if ($type instanceof NumericString) {
            return new DecoratedType(new BuiltinType('string'), new TypeConstraints(pattern: '^-?(?:\d+|\d*\.\d+)$'));
        }

        if ($type instanceof String_) {
            return new BuiltinType('string');
        }

        return $this->parseVersionSpecificStringType($type);
    }

    private function parseBooleanType(PhpDocumentorType $type): ?Type
    {
        if ($type instanceof Boolean) {
            return new BuiltinType('bool');
        }

        return null;
    }

    private function parseScalarType(PhpDocumentorType $type): ?Type
    {
        if ($type instanceof Null_) {
            return new BuiltinType('null');
        }

        if ($type instanceof Float_) {
            return new BuiltinType('float');
        }

        if ($type instanceof Mixed_) {
            return new BuiltinType('mixed');
        }

        if ($type instanceof Callable_ || $type instanceof Resource_ || $type instanceof Void_ || $type instanceof Never_) {
            return new UnknownType();
        }

        if ($type instanceof Numeric_) {
            return new UnionType([
                new DecoratedType(new BuiltinType('string'), new TypeConstraints(pattern: '^-?(?:\d+|\d*\.\d+)$')),
                new BuiltinType('int'),
                new BuiltinType('float'),
            ]);
        }

        return $this->parseVersionSpecificScalarType($type);
    }

    protected function scalarUnionType(): Type
    {
        return new UnionType([
            new BuiltinType('string'),
            new BuiltinType('int'),
            new BuiltinType('float'),
            new BuiltinType('bool'),
        ]);
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    abstract protected function parseCollectionType(PhpDocumentorType $type, \ReflectionClass $context): ?Type;

    protected function parseVersionSpecificLiteralType(PhpDocumentorType $type): ?Type
    {
        return null;
    }

    protected function parseVersionSpecificStringType(PhpDocumentorType $type): ?Type
    {
        return null;
    }

    protected function parseVersionSpecificScalarType(PhpDocumentorType $type): ?Type
    {
        return null;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function parseShapeType(PhpDocumentorType $type, \ReflectionClass $context): ?Type
    {
        if ($type instanceof ArrayShape || $type instanceof ObjectShape) {
            return new InlineObjectType($this->buildInlineObjectShape($type->getItems(), $context));
        }

        return null;
    }

    /**
     * @param array<int, ShapeItem> $items
     * @param \ReflectionClass<object> $context
     */
    private function buildInlineObjectShape(array $items, \ReflectionClass $context): InlineObjectDefinition
    {
        $inlineObject = new InlineObjectDefinition(id: 'phpdoc-shape');

        foreach ($items as $item) {
            $itemName = $item->getKey();
            if ($itemName === null || $itemName === '') {
                continue;
            }

            $nestedProperty = new InlineFieldDefinition(fieldName: $itemName);
            $nestedProperty->setType($this->parse($item->getValue(), $context));
            $nestedProperty->setRequired(!$item->isOptional());

            $inlineObject->addProperty($nestedProperty);
        }

        return $inlineObject;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function parseObjectType(PhpDocumentorType $type, \ReflectionClass $context): ?Type
    {
        if ($type instanceof Object_) {
            $fqsen = $type->getFqsen() !== null ? (string) $type->getFqsen() : null;
            return $this->mapClassLikeName($fqsen);
        }

        if ($type instanceof Self_ || $type instanceof Static_ || $type instanceof This) {
            return new ClassLikeType($context->getName());
        }

        if ($type instanceof Parent_) {
            $parent = $context->getParentClass();
            if ($parent !== false) {
                return new ClassLikeType($parent->getName());
            }

            return new UnknownType();
        }

        return null;
    }

    protected function mapClassLikeName(?string $name): Type
    {
        if ($name !== null) {
            $name = ltrim($name, '\\');
        }

        if ($name === null || $name === '') {
            return new UnknownType();
        }

        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            return new UnknownType();
        }

        if (enum_exists($name)) {
            return new EnumType($name);
        }

        return new ClassLikeType($name);
    }
}
