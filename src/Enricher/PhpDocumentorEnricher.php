<?php

namespace Zeusi\JsonSchemaGenerator\Enricher;

use phpDocumentor\Reflection\DocBlock\Tags\BaseTag;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\PseudoTypes\ArrayShape;
use phpDocumentor\Reflection\PseudoTypes\False_;
use phpDocumentor\Reflection\PseudoTypes\FloatValue;
use phpDocumentor\Reflection\PseudoTypes\IntegerRange;
use phpDocumentor\Reflection\PseudoTypes\IntegerValue;
use phpDocumentor\Reflection\PseudoTypes\NegativeInteger;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyArray;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyList;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyLowercaseString;
use phpDocumentor\Reflection\PseudoTypes\NonEmptyString;
use phpDocumentor\Reflection\PseudoTypes\Numeric_;
use phpDocumentor\Reflection\PseudoTypes\NumericString;
use phpDocumentor\Reflection\PseudoTypes\ObjectShape;
use phpDocumentor\Reflection\PseudoTypes\PositiveInteger;
use phpDocumentor\Reflection\PseudoTypes\ShapeItem;
use phpDocumentor\Reflection\PseudoTypes\StringValue;
use phpDocumentor\Reflection\PseudoTypes\True_;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\ArrayKey;
use phpDocumentor\Reflection\Types\Boolean;
use phpDocumentor\Reflection\Types\Collection;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Expression;
use phpDocumentor\Reflection\Types\Float_;
use phpDocumentor\Reflection\Types\Integer;
use phpDocumentor\Reflection\Types\InterfaceString;
use phpDocumentor\Reflection\Types\Intersection;
use phpDocumentor\Reflection\Types\Iterable_;
use phpDocumentor\Reflection\Types\Mixed_;
use phpDocumentor\Reflection\Types\Null_;
use phpDocumentor\Reflection\Types\Nullable;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\Parent_;
use phpDocumentor\Reflection\Types\Scalar;
use phpDocumentor\Reflection\Types\Self_;
use phpDocumentor\Reflection\Types\Static_;
use phpDocumentor\Reflection\Types\String_;
use phpDocumentor\Reflection\Types\This;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\InlineFieldDefinition;
use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\ClassLikeType;
use Zeusi\JsonSchemaGenerator\Definition\Type\DecoratedType;
use Zeusi\JsonSchemaGenerator\Definition\Type\EnumType;
use Zeusi\JsonSchemaGenerator\Definition\Type\InlineObjectType;
use Zeusi\JsonSchemaGenerator\Definition\Type\IntersectionType;
use Zeusi\JsonSchemaGenerator\Definition\Type\MapType;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExprUtils;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionType;
use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;
use Zeusi\JsonSchemaGenerator\Definition\TypeConstraints;

/**
 * Enriches properties directly using pure phpdocumentor/reflection-docblock.
 */
class PhpDocumentorEnricher implements PropertyEnricherInterface
{
    private DocBlockFactoryInterface $factory;

    public function __construct()
    {
        $this->factory = DocBlockFactory::createInstance();
    }

    public function enrich(ClassDefinition $definition, GenerationContext $context): void
    {
        /** @var class-string $className */
        $className = $definition->className;
        if (!class_exists($className)) {
            return;
        }

        $reflectionClass = new \ReflectionClass($className);

        $contextFactory = new ContextFactory();
        $context = $contextFactory->createFromReflector($reflectionClass);

        // 1. Enrich Class metadata
        $classDocComment = $reflectionClass->getDocComment();
        if ($classDocComment !== false && $classDocComment !== '') {
            $classDocBlock = $this->factory->create($classDocComment, $context);
            $classSummary = trim($classDocBlock->getSummary());
            $classDescription = trim($classDocBlock->getDescription()->render());

            if ($classSummary !== '') {
                $definition->title = $classSummary;
            }
            if ($classDescription !== '') {
                $definition->description = $classDescription;
            }
        }

        foreach ($definition->properties as $propertyName => $propertyDef) {
            try {
                if (!$reflectionClass->hasProperty($propertyName)) {
                    continue;
                }

                $reflectionProperty = $reflectionClass->getProperty($propertyName);
                $docComment = $reflectionProperty->getDocComment();

                if ($docComment === false || $docComment === '') {
                    continue;
                }

                $docBlock = $this->factory->create($docComment, $context);

                // Title and description
                $title = trim($docBlock->getSummary());
                $description = trim($docBlock->getDescription()->render());

                // Check @deprecated
                if ($docBlock->hasTag('deprecated')) {
                    $propertyDef->setTypeExpr(TypeExprUtils::decorateNonNullBranches(
                        $propertyDef->getTypeExpr(),
                        function (DecoratedType $decorated): void {
                            $decorated->annotations ??= new TypeAnnotations();
                            $decorated->annotations->deprecated = true;
                        }
                    ));
                }

                // Check @example
                $exampleTags = $docBlock->getTagsByName('example');
                if (!empty($exampleTags)) {
                    $examples = [];
                    foreach ($exampleTags as $exampleTag) {
                        /** @var BaseTag $exampleTag */
                        $example = trim($exampleTag->getDescription()?->render() ?? '');
                        if ($example !== '') {
                            $examples[] = $example;
                        }
                    }

                    if ($examples !== []) {
                        $propertyDef->setTypeExpr(TypeExprUtils::decorateNonNullBranches(
                            $propertyDef->getTypeExpr(),
                            function (DecoratedType $decorated) use ($examples): void {
                                $decorated->annotations ??= new TypeAnnotations();
                                $decorated->annotations->examples = TypeExprUtils::mergeExamples($decorated->annotations->examples, $examples);
                            }
                        ));
                    }
                }

                // Override internal types with precise PHPDoc types parsing
                $varTags = $docBlock->getTagsByName('var');
                if (!empty($varTags)) {
                    $typeExpr = null;
                    foreach ($varTags as $varTag) {
                        /** @var Var_ $varTag */
                        $type = $varTag->getType();

                        if ($title === '' && $varTag->getDescription() !== null) {
                            $title = trim($varTag->getDescription()->render());
                        }

                        if ($type !== null) {
                            $parsed = $this->parseTypeExpr($type, $reflectionClass);
                            $typeExpr = $typeExpr === null ? $parsed : new UnionType([$typeExpr, $parsed]);
                        }
                    }

                    if ($typeExpr !== null) {
                        $propertyDef->setTypeExpr($typeExpr);
                    }
                }
                if ($title !== '') {
                    $propertyDef->setTypeExpr(TypeExprUtils::decorateNonNullBranches(
                        $propertyDef->getTypeExpr(),
                        function (DecoratedType $decorated) use ($title): void {
                            $decorated->annotations ??= new TypeAnnotations();
                            $decorated->annotations->title ??= $title;
                        }
                    ));
                }
                if ($description !== '') {
                    $propertyDef->setTypeExpr(TypeExprUtils::decorateNonNullBranches(
                        $propertyDef->getTypeExpr(),
                        function (DecoratedType $decorated) use ($description): void {
                            $decorated->annotations ??= new TypeAnnotations();
                            $decorated->annotations->description ??= $description;
                        }
                    ));
                }
            } catch (\Exception $e) {
                // Gracefully ignore docblocks that are malformed
            }
        }
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function parseTypeExpr(Type $type, \ReflectionClass $context): TypeExpr
    {
        $expr = $this->parseCompositeType($type, $context)
            ?? $this->parseLiteralType($type)
            ?? $this->parseIntegerType($type)
            ?? $this->parseStringType($type)
            ?? $this->parseBooleanType($type)
            ?? $this->parseScalarType($type)
            ?? $this->parseCollectionType($type, $context)
            ?? $this->parseShapeType($type, $context)
            ?? $this->parseObjectType($type, $context);

        return $expr ?? new BuiltinType('mixed');
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function parseCompositeType(Type $type, \ReflectionClass $context): ?TypeExpr
    {
        if ($type instanceof Expression) {
            return $this->parseTypeExpr($type->getValueType(), $context);
        }

        if ($type instanceof Compound) {
            $types = [];
            foreach ($type as $subType) {
                $types[] = $this->parseTypeExpr($subType, $context);
            }

            return TypeExprUtils::normalizeUnion($types);
        }

        if ($type instanceof Intersection) {
            $types = [];
            foreach ($type as $subType) {
                $types[] = $this->parseTypeExpr($subType, $context);
            }

            return new IntersectionType($types);
        }

        if ($type instanceof Nullable) {
            return TypeExprUtils::normalizeUnion([
                $this->parseTypeExpr($type->getActualType(), $context),
                new BuiltinType('null'),
            ]);
        }

        return null;
    }

    private function parseLiteralType(Type $type): ?TypeExpr
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

        if ($type instanceof True_) {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [true]));
        }

        if ($type instanceof False_) {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [false]));
        }

        return null;
    }

    private function parseIntegerType(Type $type): ?TypeExpr
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

    private function parseStringType(Type $type): ?TypeExpr
    {
        if ($type instanceof NonEmptyString || $type instanceof NonEmptyLowercaseString) {
            return new DecoratedType(new BuiltinType('string'), new TypeConstraints(minLength: 1));
        }

        if ($type instanceof NumericString) {
            return new DecoratedType(new BuiltinType('string'), new TypeConstraints(pattern: '^-?(?:\d+|\d*\.\d+)$'));
        }

        if ($type instanceof String_ || $type instanceof InterfaceString) {
            return new BuiltinType('string');
        }

        return null;
    }

    private function parseBooleanType(Type $type): ?TypeExpr
    {
        if ($type instanceof Boolean) {
            return new BuiltinType('bool');
        }

        return null;
    }

    private function parseScalarType(Type $type): ?TypeExpr
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

        if ($type instanceof ArrayKey) {
            return TypeExprUtils::normalizeUnion([new BuiltinType('string'), new BuiltinType('int')]);
        }

        if ($type instanceof Numeric_) {
            return TypeExprUtils::normalizeUnion([
                new DecoratedType(new BuiltinType('string'), new TypeConstraints(pattern: '^-?(?:\d+|\d*\.\d+)$')),
                new BuiltinType('int'),
                new BuiltinType('float'),
            ]);
        }

        if ($type instanceof Scalar) {
            return TypeExprUtils::normalizeUnion([
                new BuiltinType('string'),
                new BuiltinType('int'),
                new BuiltinType('float'),
                new BuiltinType('bool'),
            ]);
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function parseCollectionType(Type $type, \ReflectionClass $context): ?TypeExpr
    {
        if ($type instanceof NonEmptyList) {
            return new DecoratedType($this->mapListLikeType($type, $context), new TypeConstraints(minItems: 1));
        }

        if ($type instanceof NonEmptyArray) {
            return new DecoratedType($this->mapListLikeType($type, $context), new TypeConstraints(minItems: 1));
        }

        if ($type instanceof Array_ || $type instanceof Iterable_ || $type instanceof Collection) {
            return $this->mapListLikeType($type, $context);
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapListLikeType(Array_|Iterable_|Collection $type, \ReflectionClass $context): TypeExpr
    {
        $keyType = $type->getKeyType();
        $valueType = $type->getValueType();
        $valueExpr = $this->parseTypeExpr($valueType, $context);

        if ((string) $keyType === 'string') {
            return new MapType($valueExpr);
        }

        return new ArrayType($valueExpr);
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function parseShapeType(Type $type, \ReflectionClass $context): ?TypeExpr
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
            $nestedProperty->setTypeExpr($this->parseTypeExpr($item->getValue(), $context));
            $nestedProperty->setRequired(!$item->isOptional());

            $inlineObject->addProperty($nestedProperty);
        }

        return $inlineObject;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function parseObjectType(Type $type, \ReflectionClass $context): ?TypeExpr
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

            return new BuiltinType('object');
        }

        return null;
    }

    private function mapClassLikeName(?string $name): TypeExpr
    {
        if ($name !== null) {
            $name = ltrim($name, '\\');
        }

        if ($name === null || $name === '') {
            return new BuiltinType('object');
        }

        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            return new BuiltinType('object');
        }

        if (enum_exists($name)) {
            return new EnumType($name);
        }

        return new ClassLikeType($name);
    }
}
