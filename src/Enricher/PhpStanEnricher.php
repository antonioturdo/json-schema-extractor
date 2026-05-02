<?php

namespace Zeusi\JsonSchemaGenerator\Enricher;

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
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\FieldDefinitionInterface;
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

class PhpStanEnricher implements PropertyEnricherInterface
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

    public function enrich(ClassDefinition $definition, GenerationContext $context): void
    {
        /** @var class-string $className */
        $className = $definition->className;
        if (!class_exists($className)) {
            return;
        }

        $reflectionClass = new \ReflectionClass($className);

        foreach ($definition->properties as $property) {
            if (!$reflectionClass->hasProperty($property->propertyName)) {
                continue;
            }

            $reflectionProperty = $reflectionClass->getProperty($property->propertyName);
            $docComment = $reflectionProperty->getDocComment();

            if ($docComment === false) {
                continue;
            }

            $tokens = new TokenIterator($this->lexer->tokenize($docComment));
            $phpDocNode = $this->parser->parse($tokens);

            // Prefer building TypeExpr from the phpdoc-parser AST (supports intersections and parentheses).
            $typeExpr = null;
            foreach ($phpDocNode->getVarTagValues() as $varTag) {
                $expr = $this->mapTypeNodeToExpr($varTag->type, $reflectionClass, $property);
                $typeExpr = $this->mergeTypeExpr($typeExpr, $expr);
            }

            if ($typeExpr !== null) {
                $property->setTypeExpr($typeExpr);
            }

            foreach ($phpDocNode->getTags() as $tag) {
                if ($tag->name === '@deprecated') {
                    $property->setTypeExpr($this->applyDeprecatedToExpr($property->getTypeExpr()));
                }

                if ($tag->name === '@example' && $tag->value instanceof GenericTagValueNode) {
                    $example = trim((string) $tag->value->value);
                    $property->setTypeExpr($this->applyExampleToExpr($property->getTypeExpr(), $example));
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
                $property->setTypeExpr($this->applyDescriptionToExpr($property->getTypeExpr(), $text));
            }
        }
    }

    /**
     * Builds a {@see TypeExpr} from a PHPStan {@see TypeNode}.
     *
     * This preserves higher-order constructs (intersections, parentheses) that would otherwise be lost
     * when flattening everything into a list of allowed types.
     *
     * @param \ReflectionClass<object> $context
     */
    private function mapTypeNodeToExpr(TypeNode $typeNode, \ReflectionClass $context, FieldDefinitionInterface $property): TypeExpr
    {
        if ($typeNode instanceof NullableTypeNode) {
            return TypeExprUtils::normalizeUnion([
                $this->mapTypeNodeToExpr($typeNode->type, $context, $property),
                new BuiltinType('null'),
            ]);
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            return $this->mapIdentifierToExpr($typeNode->name, $context);
        }

        if ($typeNode instanceof UnionTypeNode) {
            $types = [];
            foreach ($typeNode->types as $innerNode) {
                $types[] = $this->mapTypeNodeToExpr($innerNode, $context, $property);
            }
            return TypeExprUtils::normalizeUnion($types);
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            $types = [];
            foreach ($typeNode->types as $innerNode) {
                $types[] = $this->mapTypeNodeToExpr($innerNode, $context, $property);
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
            return new ArrayType($this->mapTypeNodeToExpr($typeNode->type, $context, $property));
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
    private function mapIdentifierToExpr(string $name, \ReflectionClass $context): TypeExpr
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
                new TypeConstraints(minLength: 1),
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
            return TypeExprUtils::normalizeUnion([new BuiltinType('string'), new BuiltinType('int')]);
        }

        if ($lowName === 'scalar') {
            return TypeExprUtils::normalizeUnion([
                new BuiltinType('string'),
                new BuiltinType('int'),
                new BuiltinType('float'),
                new BuiltinType('bool'),
            ]);
        }

        if (\in_array($lowName, ['class-string', 'interface-string', 'literal-string', 'lowercase-string'], true)) {
            return new BuiltinType('string');
        }

        if (\in_array($lowName, ['callable', 'resource', 'void', 'never'], true)) {
            return new BuiltinType('mixed');
        }

        if ($lowName === 'self' || $lowName === 'static' || $lowName === '$this') {
            return new ClassLikeType($context->getName());
        }

        if ($lowName === 'parent') {
            $parent = $context->getParentClass();
            if ($parent !== false) {
                return new ClassLikeType($parent->getName());
            }

            return new BuiltinType('object');
        }

        $basicTypes = ['string', 'int', 'float', 'bool', 'array', 'object', 'mixed', 'null', 'iterable'];
        if (\in_array($lowName, $basicTypes, true)) {
            return new BuiltinType($lowName);
        }

        $fullClassName = $this->resolveClassName($name, $context);
        if ($fullClassName === null) {
            return new BuiltinType('object');
        }

        if (enum_exists($fullClassName)) {
            return new EnumType($fullClassName);
        }

        return new ClassLikeType($fullClassName);
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private function mapGenericToExpr(GenericTypeNode $typeNode, \ReflectionClass $context, FieldDefinitionInterface $property): TypeExpr
    {
        $mainType = (string) $typeNode->type;
        $lowMainType = strtolower($mainType);

        if (\in_array($lowMainType, ['array', 'iterable', 'list'], true)) {
            if ($lowMainType === 'array' && \count($typeNode->genericTypes) === 2) {
                $keyNode = $typeNode->genericTypes[0];
                $valueNode = $typeNode->genericTypes[1];

                if ((string) $keyNode === 'string') {
                    return new MapType($this->mapTypeNodeToExpr($valueNode, $context, $property));
                }
            }

            $itemNode = $typeNode->genericTypes[\count($typeNode->genericTypes) - 1] ?? null;
            if ($itemNode instanceof TypeNode) {
                return new ArrayType($this->mapTypeNodeToExpr($itemNode, $context, $property));
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
            return TypeExprUtils::normalizeUnion([new BuiltinType('string'), new BuiltinType('int')]);
        }

        if ($lowMainType === 'value-of') {
            return new BuiltinType('mixed');
        }

        if ($lowMainType === 'class-string' || $lowMainType === 'interface-string') {
            return new BuiltinType('string');
        }

        return $this->mapIdentifierToExpr($mainType, $context);
    }

    private function mapConstTypeToExpr(ConstTypeNode $typeNode): TypeExpr
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
    private function mapArrayShapeToExpr(ArrayShapeNode $typeNode, \ReflectionClass $context, FieldDefinitionInterface $property): TypeExpr
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
    private function mapObjectShapeToExpr(ObjectShapeNode $typeNode, \ReflectionClass $context, FieldDefinitionInterface $property): TypeExpr
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
            $nestedProperty->setTypeExpr($this->mapTypeNodeToExpr($item->valueType, $context, $nestedProperty));

            if (!$item->optional) {
                $nestedProperty->setRequired(true);
            }

            $inlineObject->addProperty($nestedProperty);
        }

        return $inlineObject;
    }

    private function mergeTypeExpr(?TypeExpr $current, TypeExpr $next): TypeExpr
    {
        if ($current === null) {
            return $next;
        }

        if ($current instanceof DecoratedType && $next instanceof DecoratedType) {
            if ($this->typeExprFingerprint($current->type) === $this->typeExprFingerprint($next->type)) {
                TypeExprUtils::mergeTypeConstraints($current->constraints, $next->constraints);
                $current->annotations = TypeExprUtils::mergeTypeAnnotations($current->annotations, $next->annotations);
                return $current;
            }
        }

        if ($current instanceof DecoratedType && $this->typeExprFingerprint($current->type) === $this->typeExprFingerprint($next)) {
            return $this->mergeTypeExpr($current, new DecoratedType($next));
        }

        if ($next instanceof DecoratedType && $this->typeExprFingerprint($next->type) === $this->typeExprFingerprint($current)) {
            return $this->mergeTypeExpr(new DecoratedType($current), $next);
        }

        return TypeExprUtils::normalizeUnion([$current, $next]);
    }

    /**
     * @return array<string, mixed>
     */
    private function typeExprFingerprint(TypeExpr $type): array
    {
        if ($type instanceof DecoratedType) {
            return ['decorated' => $this->typeExprFingerprint($type->type)];
        }
        if ($type instanceof BuiltinType) {
            return ['builtin' => $type->name];
        }
        if ($type instanceof ClassLikeType) {
            return ['classLike' => $type->name];
        }
        if ($type instanceof ArrayType) {
            return ['arrayOf' => $this->typeExprFingerprint($type->type)];
        }
        if ($type instanceof MapType) {
            return ['mapOf' => $this->typeExprFingerprint($type->type)];
        }
        if ($type instanceof UnionType) {
            return ['union' => array_map(fn(TypeExpr $t) => $this->typeExprFingerprint($t), $type->types)];
        }
        if ($type instanceof IntersectionType) {
            return ['intersection' => array_map(fn(TypeExpr $t) => $this->typeExprFingerprint($t), $type->types)];
        }
        if ($type instanceof InlineObjectType) {
            return ['inlineObject' => $type->shape->id];
        }

        return ['unknown' => \get_class($type)];
    }

    private function applyDescriptionToExpr(?TypeExpr $expr, string $description): ?TypeExpr
    {
        if ($expr === null) {
            return null;
        }

        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($description): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->description ??= $description;
        }) ?? $expr;
    }

    private function applyDeprecatedToExpr(?TypeExpr $expr): ?TypeExpr
    {
        if ($expr === null) {
            return null;
        }

        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->deprecated = true;
        }) ?? $expr;
    }

    private function applyExampleToExpr(?TypeExpr $expr, string $example): ?TypeExpr
    {
        if ($expr === null) {
            return null;
        }

        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($example): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->examples = TypeExprUtils::mergeExamples($decorated->annotations->examples, [$example]);
        }) ?? $expr;
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
