<?php

namespace Zeusi\JsonSchemaExtractor\Enricher;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\FieldDefinitionInterface;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeUtils;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;

class PhpStanEnricher implements EnricherInterface
{
    private Lexer $lexer;
    private PhpDocParser $parser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);

        $constParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constParser);
        $this->parser = new PhpDocParser($config, $typeParser, $constParser);
    }

    public function enrich(ClassDefinition $definition, ExtractionContext $context, EnrichmentRuntime $runtime): void
    {
        /** @var class-string $className */
        $className = $definition->getClassName();
        if (!class_exists($className)) {
            return;
        }

        $reflectionClass = new \ReflectionClass($className);

        $this->enrichPromotedPropertiesFromConstructorParams($definition, $reflectionClass, $runtime);

        foreach ($definition->getProperties() as $property) {
            if (!$reflectionClass->hasProperty($property->getName())) {
                continue;
            }

            $reflectionProperty = $reflectionClass->getProperty($property->getName());
            $docComment = $reflectionProperty->getDocComment();

            if ($docComment === false) {
                continue;
            }

            $tokens = new TokenIterator($this->lexer->tokenize($docComment));
            $phpDocNode = $this->parser->parse($tokens);

            // Prefer building Type from the phpdoc-parser AST (supports intersections and parentheses).
            $type = null;
            foreach ($phpDocNode->getVarTagValues() as $varTag) {
                $expr = $this->mapTypeNodeToType($varTag->type, $reflectionClass, $property);
                $type = $this->mergeVarTypes($type, $expr);
            }

            if ($type !== null) {
                $runtime->fieldDefinitionUpdater->applyCompatibleDeclaredType($property, $type);
            }

            foreach ($phpDocNode->getTags() as $tag) {
                if ($tag->name === '@deprecated') {
                    $runtime->fieldDefinitionUpdater->applyDeprecated($property);
                }

                if ($tag->name === '@example' && $tag->value instanceof GenericTagValueNode) {
                    $example = trim((string) $tag->value->value);
                    if ($example !== '') {
                        $runtime->fieldDefinitionUpdater->applyExamples($property, [$example]);
                    }
                }
            }

            $text = '';
            foreach ($phpDocNode->children as $child) {
                if ($child instanceof PhpDocTextNode) {
                    $text .= "\n" . $child->text;
                }
            }

            $text = trim($text);
            if ($text !== '') {
                $runtime->fieldDefinitionUpdater->applyDescription($property, $text);
            }
        }
    }

    /**
     * @param \ReflectionClass<object> $reflectionClass
     */
    private function enrichPromotedPropertiesFromConstructorParams(ClassDefinition $definition, \ReflectionClass $reflectionClass, EnrichmentRuntime $runtime): void
    {
        $constructor = $reflectionClass->getConstructor();
        if ($constructor === null) {
            return;
        }

        $constructorDocComment = $constructor->getDocComment();
        if ($constructorDocComment === false || $constructorDocComment === '') {
            return;
        }

        $promotedParameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isPromoted()) {
                $promotedParameters[$parameter->getName()] = true;
            }
        }

        if ($promotedParameters === []) {
            return;
        }

        $tokens = new TokenIterator($this->lexer->tokenize($constructorDocComment));
        $phpDocNode = $this->parser->parse($tokens);

        foreach ($phpDocNode->getParamTagValues() as $paramTag) {
            $parameterName = ltrim($paramTag->parameterName, '$');
            if (!isset($promotedParameters[$parameterName])) {
                continue;
            }

            $property = $definition->getProperty($parameterName);
            if ($property === null) {
                continue;
            }

            $runtime->fieldDefinitionUpdater->applyCompatibleDeclaredType(
                $property,
                $this->mapTypeNodeToType($paramTag->type, $reflectionClass, $property)
            );
        }
    }

    /**
     * Builds a {@see Type} from a PHPStan {@see TypeNode}.
     *
     * This preserves higher-order constructs (intersections, parentheses) that would otherwise be lost
     * when flattening everything into a list of allowed types.
     *
     * @param \ReflectionClass<object> $context
     */
    private function mapTypeNodeToType(TypeNode $typeNode, \ReflectionClass $context, FieldDefinitionInterface $property): Type
    {
        if ($typeNode instanceof NullableTypeNode) {
            return TypeUtils::normalizeUnion([
                $this->mapTypeNodeToType($typeNode->type, $context, $property),
                new BuiltinType('null'),
            ]);
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            return $this->mapIdentifierToExpr($typeNode->name, $context);
        }

        if ($typeNode instanceof UnionTypeNode) {
            $types = [];
            foreach ($typeNode->types as $innerNode) {
                $types[] = $this->mapTypeNodeToType($innerNode, $context, $property);
            }
            return TypeUtils::normalizeUnion($types);
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            $types = [];
            foreach ($typeNode->types as $innerNode) {
                $types[] = $this->mapTypeNodeToType($innerNode, $context, $property);
            }
            return new IntersectionType($types);
        }

        if ($typeNode instanceof GenericTypeNode) {
            return $this->mapGenericToExpr($typeNode, $context, $property);
        }

        if ($typeNode instanceof ArrayShapeNode) {
            return $this->mapArrayShapeToExpr($typeNode, $context, $property);
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return new ArrayType($this->mapTypeNodeToType($typeNode->type, $context, $property));
        }

        if ($typeNode instanceof ObjectShapeNode) {
            return $this->mapObjectShapeToExpr($typeNode, $context, $property);
        }

        if ($typeNode instanceof ThisTypeNode) {
            return new ClassLikeType($context->getName());
        }

        if ($typeNode instanceof ConstTypeNode) {
            return $this->mapConstTypeToExpr($typeNode);
        }

        return new BuiltinType('mixed');
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapIdentifierToExpr(string $name, \ReflectionClass $context): Type
    {
        $lowName = strtolower($name);

        if ($lowName === 'positive-int') {
            return new DecoratedType(
                new BuiltinType('int'),
                new TypeConstraints(minimum: 1),
            );
        }

        if ($lowName === 'negative-int') {
            return new DecoratedType(
                new BuiltinType('int'),
                new TypeConstraints(maximum: -1),
            );
        }

        if ($lowName === 'non-empty-string') {
            return new DecoratedType(
                new BuiltinType('string'),
                new TypeConstraints(minLength: 1),
            );
        }

        if ($lowName === 'non-empty-lowercase-string') {
            return new DecoratedType(
                new BuiltinType('string'),
                new TypeConstraints(minLength: 1, pattern: '^[^A-Z]*$'),
            );
        }

        if ($lowName === 'numeric-string') {
            return new DecoratedType(
                new BuiltinType('string'),
                new TypeConstraints(pattern: '^-?(?:\d+|\d*\.\d+)$'),
            );
        }

        if ($lowName === 'non-empty-array' || $lowName === 'non-empty-list') {
            return new DecoratedType(
                new BuiltinType('array'),
                new TypeConstraints(minItems: 1),
            );
        }

        if ($lowName === 'true') {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [true]));
        }

        if ($lowName === 'false') {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [false]));
        }

        if ($lowName === 'array-key') {
            return TypeUtils::normalizeUnion([new BuiltinType('string'), new BuiltinType('int')]);
        }

        if ($lowName === 'scalar') {
            return TypeUtils::normalizeUnion([
                new BuiltinType('string'),
                new BuiltinType('int'),
                new BuiltinType('float'),
                new BuiltinType('bool'),
            ]);
        }

        if ($lowName === 'lowercase-string') {
            return new DecoratedType(
                new BuiltinType('string'),
                new TypeConstraints(pattern: '^[^A-Z]*$'),
            );
        }

        if (\in_array($lowName, ['class-string', 'interface-string', 'literal-string'], true)) {
            return new BuiltinType('string');
        }

        if (\in_array($lowName, ['callable', 'resource', 'void', 'never'], true)) {
            // PHPDoc types that cannot be faithfully represented as a JSON value schema.
            return new UnknownType();
        }

        if ($lowName === 'self' || $lowName === 'static' || $lowName === '$this') {
            return new ClassLikeType($context->getName());
        }

        if ($lowName === 'parent') {
            $parent = $context->getParentClass();
            if ($parent !== false) {
                return new ClassLikeType($parent->getName());
            }

            return new UnknownType();
        }

        $basicTypes = ['string', 'int', 'float', 'bool', 'array', 'object', 'mixed', 'null', 'iterable'];
        if (\in_array($lowName, $basicTypes, true)) {
            return new BuiltinType($lowName);
        }

        $fullClassName = $this->resolveClassName($name, $context);
        if ($fullClassName === null) {
            return new UnknownType();
        }

        if (enum_exists($fullClassName)) {
            return new EnumType($fullClassName);
        }

        return new ClassLikeType($fullClassName);
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapGenericToExpr(GenericTypeNode $typeNode, \ReflectionClass $context, FieldDefinitionInterface $property): Type
    {
        $mainType = (string) $typeNode->type;
        $lowMainType = strtolower($mainType);

        if (\in_array($lowMainType, ['array', 'iterable', 'list'], true)) {
            if ($lowMainType === 'array' && \count($typeNode->genericTypes) === 2) {
                $keyNode = $typeNode->genericTypes[0];
                $valueNode = $typeNode->genericTypes[1];

                if ((string) $keyNode === 'string') {
                    return new MapType($this->mapTypeNodeToType($valueNode, $context, $property));
                }
            }

            $itemNode = $typeNode->genericTypes[\count($typeNode->genericTypes) - 1] ?? null;
            if ($itemNode instanceof TypeNode) {
                return new ArrayType($this->mapTypeNodeToType($itemNode, $context, $property));
            }

            return new ArrayType(new BuiltinType('mixed'));
        }

        if ($lowMainType === 'int' && \count($typeNode->genericTypes) === 2) {
            $constraints = new TypeConstraints();
            $minNode = $typeNode->genericTypes[0];
            $maxNode = $typeNode->genericTypes[1];

            if ($minNode instanceof ConstTypeNode) {
                $constraints->minimum = (int) (string) $minNode;
            }

            if ($maxNode instanceof ConstTypeNode) {
                $constraints->maximum = (int) (string) $maxNode;
            }

            return new DecoratedType(new BuiltinType('int'), $constraints);
        }

        if ($lowMainType === 'int-mask' || $lowMainType === 'int-mask-of') {
            return new BuiltinType('int');
        }

        if ($lowMainType === 'key-of') {
            return TypeUtils::normalizeUnion([new BuiltinType('string'), new BuiltinType('int')]);
        }

        if ($lowMainType === 'value-of') {
            return new BuiltinType('mixed');
        }

        if ($lowMainType === 'class-string' || $lowMainType === 'interface-string') {
            return new BuiltinType('string');
        }

        return $this->mapIdentifierToExpr($mainType, $context);
    }

    private function mapConstTypeToExpr(ConstTypeNode $typeNode): Type
    {
        $constExpr = $typeNode->constExpr;

        if ($constExpr instanceof ConstExprStringNode) {
            return new DecoratedType(new BuiltinType('string'), new TypeConstraints(enum: [$constExpr->value]));
        }

        if ($constExpr instanceof ConstExprIntegerNode) {
            return new DecoratedType(new BuiltinType('int'), new TypeConstraints(enum: [(int) $constExpr->value]));
        }

        if ($constExpr instanceof ConstExprFloatNode) {
            return new DecoratedType(new BuiltinType('float'), new TypeConstraints(enum: [(float) $constExpr->value]));
        }

        if ($constExpr instanceof ConstExprTrueNode) {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [true]));
        }

        if ($constExpr instanceof ConstExprFalseNode) {
            return new DecoratedType(new BuiltinType('bool'), new TypeConstraints(enum: [false]));
        }

        if ($constExpr instanceof ConstExprNullNode) {
            return new BuiltinType('null');
        }

        return new BuiltinType('mixed');
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapArrayShapeToExpr(ArrayShapeNode $typeNode, \ReflectionClass $context, FieldDefinitionInterface $property): Type
    {
        $inlineObjectId = $context->getName() . '::$' . $property->getName() . ' (shape)';
        $inlineObject = $this->buildInlineObjectShape($inlineObjectId, $typeNode->items, $context);

        $expr = new InlineObjectType($inlineObject);

        if ($typeNode->kind === ArrayShapeNode::KIND_NON_EMPTY_ARRAY || $typeNode->kind === ArrayShapeNode::KIND_NON_EMPTY_LIST) {
            return new DecoratedType(
                $expr,
                new TypeConstraints(minItems: 1)
            );
        }

        return $expr;
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapObjectShapeToExpr(ObjectShapeNode $typeNode, \ReflectionClass $context, FieldDefinitionInterface $property): Type
    {
        $inlineObjectId = $context->getName() . '::$' . $property->getName() . ' (object-shape)';
        return new InlineObjectType($this->buildInlineObjectShape($inlineObjectId, $typeNode->items, $context));
    }

    /**
     * @param array<int, ArrayShapeItemNode|ObjectShapeItemNode> $items
     * @param \ReflectionClass<object> $context
     */
    private function buildInlineObjectShape(string $id, array $items, \ReflectionClass $context): InlineObjectDefinition
    {
        $inlineObject = new InlineObjectDefinition(id: $id);

        foreach ($items as $item) {
            $itemName = (string) $item->keyName;
            $itemName = trim($itemName, "'\"");

            if ($itemName === '') {
                continue;
            }

            $nestedProperty = new InlineFieldDefinition(fieldName: $itemName);
            $nestedProperty->setType($this->mapTypeNodeToType($item->valueType, $context, $nestedProperty));

            if (!$item->optional) {
                $nestedProperty->setRequired(true);
            }

            $inlineObject->addProperty($nestedProperty);
        }

        return $inlineObject;
    }

    /**
     * Merges two `@var` type declarations from the same PHPDoc block into a single Type.
     */
    private function mergeVarTypes(?Type $current, Type $next): Type
    {
        if ($current === null) {
            return $next;
        }

        $normalizedCurrent = $current;
        $normalizedNext = $next;

        $mergedCollectionDecoration = $this->mergeDecoratedCollectionPlaceholder($normalizedCurrent, $normalizedNext);
        if ($mergedCollectionDecoration !== null) {
            return $mergedCollectionDecoration;
        }

        $currentBaseType = $normalizedCurrent instanceof DecoratedType ? $normalizedCurrent->type : $normalizedCurrent;
        $nextBaseType = $normalizedNext instanceof DecoratedType ? $normalizedNext->type : $normalizedNext;

        if ($this->typeFingerprint($currentBaseType) === $this->typeFingerprint($nextBaseType)) {
            return TypeUtils::mergeTypeConstraintsAndAnnotations($normalizedCurrent, $normalizedNext) ?? $normalizedNext;
        }

        return TypeUtils::normalizeUnion([$normalizedCurrent, $normalizedNext]);
    }

    private function mergeDecoratedCollectionPlaceholder(Type $current, Type $next): ?Type
    {
        if ($current instanceof DecoratedType && $current->type instanceof BuiltinType && $current->type->name === 'array' && $next instanceof ArrayType) {
            return new DecoratedType($next, $current->constraints, $current->annotations);
        }

        if ($next instanceof DecoratedType && $next->type instanceof BuiltinType && $next->type->name === 'array' && $current instanceof ArrayType) {
            return new DecoratedType($current, $next->constraints, $next->annotations);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function typeFingerprint(Type $type): array
    {
        if ($type instanceof DecoratedType) {
            return ['decorated' => $this->typeFingerprint($type->type)];
        }
        if ($type instanceof BuiltinType) {
            return ['builtin' => $type->name];
        }
        if ($type instanceof ClassLikeType) {
            return ['classLike' => $type->name];
        }
        if ($type instanceof ArrayType) {
            return ['arrayOf' => $this->typeFingerprint($type->type)];
        }
        if ($type instanceof MapType) {
            return ['mapOf' => $this->typeFingerprint($type->type)];
        }
        if ($type instanceof UnionType) {
            return ['union' => array_map(fn(Type $t) => $this->typeFingerprint($t), $type->types)];
        }
        if ($type instanceof IntersectionType) {
            return ['intersection' => array_map(fn(Type $t) => $this->typeFingerprint($t), $type->types)];
        }
        if ($type instanceof InlineObjectType) {
            return ['inlineObject' => $type->shape->getId()];
        }

        return ['unknown' => \get_class($type)];
    }

    /**
     * @param \ReflectionClass<object> $context
     * @return class-string|null
     */
    private function resolveClassName(string $name, \ReflectionClass $context): ?string
    {
        if (str_contains($name, '\\')) {
            $class = ltrim($name, '\\');
            if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
                return $class;
            }
            return null;
        }

        // Basic attempt: same namespace
        $namespace = $context->getNamespaceName();
        $candidate = ($namespace ? $namespace . '\\' : '') . $name;
        if (class_exists($candidate) || interface_exists($candidate) || enum_exists($candidate)) {
            return $candidate;
        }

        if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
            return $name;
        }

        return null;
    }
}
