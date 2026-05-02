<?php

namespace Zeusi\JsonSchemaGenerator\Definition\Type;

use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;
use Zeusi\JsonSchemaGenerator\Definition\TypeConstraints;

/**
 * Utilities for inspecting and transforming {@see TypeExpr} trees.
 *
 * This class centralizes common operations that would otherwise be duplicated across discoverers/enrichers,
 * such as:
 * - checking whether a type allows null
 * - decorating only non-null branches
 * - preserving an existing nullable union when replacing a type expression
 * - normalizing unions (flatten + de-duplicate)
 *
 * @internal
 */
final class TypeExprUtils
{
    public static function allowsNull(TypeExpr $expr): bool
    {
        if ($expr instanceof BuiltinType) {
            return $expr->name === 'null';
        }

        if ($expr instanceof DecoratedType) {
            return self::allowsNull($expr->type);
        }

        if ($expr instanceof UnionType) {
            foreach ($expr->types as $subType) {
                if (self::allowsNull($subType)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function preserveNullableUnion(?TypeExpr $original, TypeExpr $replacement): TypeExpr
    {
        if ($original === null) {
            return $replacement;
        }

        if (!self::allowsNull($original)) {
            return $replacement;
        }

        return self::normalizeUnion([new BuiltinType('null'), $replacement]);
    }

    /**
     * Recursively rewrites a {@see TypeExpr} tree.
     *
     * The callback is invoked after child nodes have been rewritten.
     * Return a replacement expression to substitute the current node, or `null` to keep the current node.
     *
     * @param callable(TypeExpr): ?TypeExpr $rewrite
     */
    public static function rewrite(?TypeExpr $expr, callable $rewrite): ?TypeExpr
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof DecoratedType) {
            $expr->type = self::rewrite($expr->type, $rewrite) ?? $expr->type;
            $expr = self::flattenDecoratedType($expr);
        } elseif ($expr instanceof UnionType) {
            $expr->types = array_map(
                static fn(TypeExpr $type): TypeExpr => self::rewrite($type, $rewrite) ?? $type,
                $expr->types
            );
            $expr = self::normalizeUnion($expr->types);
        } elseif ($expr instanceof IntersectionType) {
            $expr->types = array_map(
                static fn(TypeExpr $type): TypeExpr => self::rewrite($type, $rewrite) ?? $type,
                $expr->types
            );
        } elseif ($expr instanceof ArrayType) {
            $expr->type = self::rewrite($expr->type, $rewrite) ?? $expr->type;
        } elseif ($expr instanceof MapType) {
            $expr->type = self::rewrite($expr->type, $rewrite) ?? $expr->type;
        }

        $rewritten = $rewrite($expr) ?? $expr;
        if ($rewritten instanceof DecoratedType) {
            return self::flattenDecoratedType($rewritten);
        }

        return $rewritten;
    }

    /**
     * Decorate all non-null branches of the expression and let the callback mutate the {@see DecoratedType}.
     *
     * The callback is invoked only for branches that are not the `null` builtin.
     *
     * @param callable(DecoratedType): void $mutate
     */
    public static function decorateNonNullBranches(?TypeExpr $expr, callable $mutate): ?TypeExpr
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof UnionType) {
            $expr->types = array_map(
                static fn(TypeExpr $t): TypeExpr => self::decorateNonNullBranches($t, $mutate) ?? $t,
                $expr->types
            );
            return self::normalizeUnion($expr->types);
        }

        if ($expr instanceof DecoratedType) {
            $inner = $expr->type;
            if ($inner instanceof BuiltinType && $inner->name === 'null') {
                return $expr;
            }

            $expr->annotations ??= new TypeAnnotations();
            $mutate($expr);
            return $expr;
        }

        if ($expr instanceof BuiltinType && $expr->name === 'null') {
            return $expr;
        }

        $decorated = new DecoratedType($expr, new TypeConstraints(), new TypeAnnotations());
        $mutate($decorated);
        return $decorated;
    }

    /**
     * Returns a canonical union expression from the given alternatives.
     *
     * Nested unions are flattened, duplicate alternatives are removed using a structural fingerprint,
     * and single-alternative unions collapse to that alternative directly.
     *
     * @param list<TypeExpr> $types
     */
    public static function normalizeUnion(array $types): TypeExpr
    {
        $flat = [];

        $queue = $types;
        while ($queue !== []) {
            $type = array_shift($queue);
            if ($type instanceof UnionType) {
                foreach ($type->types as $inner) {
                    $queue[] = $inner;
                }
                continue;
            }
            $flat[] = $type;
        }

        // De-duplicate by a stable fingerprint.
        $unique = [];
        foreach ($flat as $type) {
            $unique[self::fingerprint($type)] = $type;
        }
        $flat = array_values($unique);

        if (\count($flat) === 1) {
            return $flat[0];
        }

        return new UnionType($flat);
    }

    /**
     * Builds a stable structural identifier for a type expression.
     *
     * The fingerprint is used to de-duplicate union alternatives. Decorations are included because
     * two equal base types with different constraints/annotations can produce different schemas.
     */
    private static function fingerprint(TypeExpr $expr): string
    {
        if ($expr instanceof BuiltinType) {
            return 'b:' . $expr->name;
        }
        if ($expr instanceof ClassLikeType) {
            return 'c:' . $expr->name;
        }
        if ($expr instanceof ArrayType) {
            return 'a:' . self::fingerprint($expr->type);
        }
        if ($expr instanceof MapType) {
            return 'm:' . self::fingerprint($expr->type);
        }
        if ($expr instanceof InlineObjectType) {
            return 'o:' . $expr->shape->id;
        }
        if ($expr instanceof IntersectionType) {
            $inner = array_map(static fn(TypeExpr $t) => self::fingerprint($t), $expr->types);
            sort($inner);
            return 'i:' . implode('|', $inner);
        }
        if ($expr instanceof UnionType) {
            $inner = array_map(static fn(TypeExpr $t) => self::fingerprint($t), $expr->types);
            sort($inner);
            return 'u:' . implode('|', $inner);
        }
        if ($expr instanceof DecoratedType) {
            // Decoration affects schema, so it must affect fingerprint.
            return 'd:' . self::fingerprint($expr->type) . ':' . md5(serialize([$expr->constraints, $expr->annotations]));
        }

        return md5(serialize($expr));
    }

    /**
     * Collapses nested decorations into a single {@see DecoratedType}.
     *
     * Outer metadata wins when both levels define the same constraint/annotation, while missing outer
     * metadata is filled from the inner decoration.
     */
    private static function flattenDecoratedType(DecoratedType $type): DecoratedType
    {
        if (!$type->type instanceof DecoratedType) {
            return $type;
        }

        $inner = self::flattenDecoratedType($type->type);
        self::mergeTypeConstraints($type->constraints, $inner->constraints);
        $type->annotations = self::mergeTypeAnnotations($type->annotations, $inner->annotations);
        $type->type = $inner->type;

        return $type;
    }

    /**
     * Fills missing constraints on the target from the source.
     *
     * Existing target values are preserved so outer decorations keep precedence over inner decorations.
     */
    public static function mergeTypeConstraints(TypeConstraints $target, TypeConstraints $source): void
    {
        if ($target->enum === [] && $source->enum !== []) {
            $target->enum = $source->enum;
        }

        $target->minimum ??= $source->minimum;
        $target->maximum ??= $source->maximum;
        $target->exclusiveMinimum ??= $source->exclusiveMinimum;
        $target->exclusiveMaximum ??= $source->exclusiveMaximum;
        $target->multipleOf ??= $source->multipleOf;

        $target->minLength ??= $source->minLength;
        $target->maxLength ??= $source->maxLength;
        $target->pattern ??= $source->pattern;

        $target->minItems ??= $source->minItems;
        $target->maxItems ??= $source->maxItems;
    }

    /**
     * Fills missing annotations on the target from the source.
     *
     * Existing target values are preserved so outer decorations keep precedence over inner decorations.
     */
    public static function mergeTypeAnnotations(?TypeAnnotations $target, ?TypeAnnotations $source): ?TypeAnnotations
    {
        if ($source === null) {
            return $target;
        }

        if ($target === null) {
            return $source;
        }

        $target->title ??= $source->title;
        $target->description ??= $source->description;
        $target->format ??= $source->format;
        if (!$target->deprecated && $source->deprecated) {
            $target->deprecated = true;
        }
        if ($source->examples !== []) {
            $target->examples = self::mergeExamples($target->examples, $source->examples);
        }
        $target->default ??= $source->default;

        return $target;
    }

    /**
     * @param list<mixed> $targetExamples
     * @param list<mixed> $sourceExamples
     * @return list<mixed>
     */
    public static function mergeExamples(array $targetExamples, array $sourceExamples): array
    {
        $examples = $targetExamples;
        $fingerprints = [];
        foreach ($examples as $example) {
            $fingerprints[md5(serialize($example))] = true;
        }

        foreach ($sourceExamples as $example) {
            $fingerprint = md5(serialize($example));
            if (isset($fingerprints[$fingerprint])) {
                continue;
            }

            $examples[] = $example;
            $fingerprints[$fingerprint] = true;
        }

        return $examples;
    }
}
