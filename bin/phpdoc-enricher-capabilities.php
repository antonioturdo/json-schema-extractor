#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\FieldDefinitionInterface;
use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\ClassLikeType;
use Zeusi\JsonSchemaGenerator\Definition\Type\DecoratedType;
use Zeusi\JsonSchemaGenerator\Definition\Type\EnumType;
use Zeusi\JsonSchemaGenerator\Definition\Type\InlineObjectType;
use Zeusi\JsonSchemaGenerator\Definition\Type\MapType;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionType;
use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;
use Zeusi\JsonSchemaGenerator\Definition\TypeConstraints;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\SymfonyPhpDocPropertyEnricher;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\PhpDocObject;

function propertyOf(ClassDefinition $definition, string $propertyName): FieldDefinitionInterface
{
    $property = $definition->getProperty($propertyName);
    if ($property === null) {
        throw new RuntimeException(\sprintf('Property "%s" not found in %s.', $propertyName, $definition->className));
    }

    return $property;
}

function unwrapDecorated(TypeExpr $expr): TypeExpr
{
    while ($expr instanceof DecoratedType) {
        $expr = $expr->type;
    }

    return $expr;
}

function findFirstDecorated(?TypeExpr $expr): ?DecoratedType
{
    if ($expr === null) {
        return null;
    }

    if ($expr instanceof DecoratedType) {
        return $expr;
    }

    $expr = unwrapDecorated($expr);
    if ($expr instanceof UnionType) {
        foreach ($expr->types as $subType) {
            $found = findFirstDecorated($subType);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function annotationsOf(FieldDefinitionInterface $property): TypeAnnotations
{
    $decorated = findFirstDecorated($property->getTypeExpr());
    if ($decorated === null || $decorated->annotations === null) {
        return new TypeAnnotations();
    }

    return $decorated->annotations;
}

function constraintsOf(FieldDefinitionInterface $property): TypeConstraints
{
    $decorated = findFirstDecorated($property->getTypeExpr());
    if ($decorated === null) {
        return new TypeConstraints();
    }

    return $decorated->constraints;
}

function arrayValueType(FieldDefinitionInterface $property): ?TypeExpr
{
    $expr = $property->getTypeExpr();
    if ($expr === null) {
        return null;
    }

    $expr = unwrapDecorated($expr);
    if (!$expr instanceof ArrayType) {
        return null;
    }

    return $expr->type;
}

function mapValueType(FieldDefinitionInterface $property): ?TypeExpr
{
    $expr = $property->getTypeExpr();
    if ($expr === null) {
        return null;
    }

    $expr = unwrapDecorated($expr);
    if (!$expr instanceof MapType) {
        return null;
    }

    return $expr->type;
}

function inlineObjectShape(TypeExpr $expr): ?InlineObjectDefinition
{
    $expr = unwrapDecorated($expr);
    if (!$expr instanceof InlineObjectType) {
        return null;
    }

    return $expr->shape;
}

function yesNo(bool $value, ?string $details = null): string
{
    if (!$value) {
        return 'No';
    }

    if ($details === null || $details === '') {
        return 'Yes';
    }

    return 'Yes (' . $details . ')';
}

function code(string $value): string
{
    return '`' . str_replace('`', '\`', $value) . '`';
}

function shortClassName(string $className): string
{
    $className = ltrim($className, '\\');
    $pos = strrpos($className, '\\');
    if ($pos === false) {
        return $className;
    }

    return substr($className, $pos + 1);
}

function typeLabel(?TypeExpr $expr): ?string
{
    if ($expr === null) {
        return null;
    }

    $expr = unwrapDecorated($expr);

    if ($expr instanceof BuiltinType) {
        return $expr->name;
    }

    if ($expr instanceof ClassLikeType) {
        return shortClassName($expr->name);
    }

    if ($expr instanceof EnumType) {
        return shortClassName($expr->className);
    }

    if ($expr instanceof ArrayType) {
        return 'array<' . (typeLabel($expr->type) ?? 'mixed') . '>';
    }

    if ($expr instanceof MapType) {
        return 'array<string, ' . (typeLabel($expr->type) ?? 'mixed') . '>';
    }

    if ($expr instanceof InlineObjectType) {
        return 'object';
    }

    if ($expr instanceof UnionType) {
        $labels = array_values(array_filter(array_map(typeLabel(...), $expr->types)));
        sort($labels);
        return implode('|', $labels);
    }

    return null;
}

/**
 * @return list<string>
 */
function unionLabels(?TypeExpr $expr): array
{
    if ($expr === null) {
        return [];
    }

    $expr = unwrapDecorated($expr);
    if (!$expr instanceof UnionType) {
        $label = typeLabel($expr);
        return $label === null ? [] : [$label];
    }

    $labels = array_values(array_filter(array_map(typeLabel(...), $expr->types)));
    sort($labels);
    return $labels;
}

function mdCell(string $value): string
{
    $value = str_replace("\n", '<br>', $value);
    $value = str_replace('|', '\|', $value);
    return $value;
}

$discoverer = new ReflectionPropertyDiscoverer(setTitleFromClassName: false);
$context = new GenerationContext();

$enrichers = [
    'PhpDocumentorEnricher' => new PhpDocumentorEnricher(),
    'PhpStanEnricher' => new PhpStanEnricher(),
    'SymfonyPhpDocPropertyEnricher' => new SymfonyPhpDocPropertyEnricher(),
];

/** @var array<string, ClassDefinition> $definitions */
$definitions = [];
foreach ($enrichers as $name => $enricher) {
    $definition = $discoverer->discover(PhpDocObject::class);
    $enricher->enrich($definition, $context);
    $definitions[$name] = $definition;
}

$rows = [
    [
        'property' => '(class)',
        'annotation' => 'Class PHPDoc summary/description',
        'eval' => static function (ClassDefinition $definition): string {
            $hasTitle = $definition->title !== null && $definition->title !== '';
            $hasDescription = $definition->description !== null && $definition->description !== '';
            return yesNo($hasTitle || $hasDescription, implode(', ', array_filter([
                $hasTitle ? 'title=' . code($definition->title ?? '') : null,
                $hasDescription ? 'description' : null,
            ])));
        },
    ],
    [
        'property' => 'promotedList',
        'annotation' => 'Promoted property: @param list<string> (constructor)',
        'eval' => static function (ClassDefinition $definition): string {
            $valueType = typeLabel(arrayValueType(propertyOf($definition, 'promotedList')));
            return yesNo($valueType === 'string', $valueType ? 'items=' . code($valueType) : null);
        },
    ],
    [
        'property' => 'promotedVarList',
        'annotation' => 'Promoted property: @var list<string> (property doc)',
        'eval' => static function (ClassDefinition $definition): string {
            $valueType = typeLabel(arrayValueType(propertyOf($definition, 'promotedVarList')));
            return yesNo($valueType === 'string', $valueType ? 'items=' . code($valueType) : null);
        },
    ],
    [
        'property' => 'id',
        'annotation' => '@deprecated',
        'eval' => static fn(ClassDefinition $definition): string => yesNo(annotationsOf(propertyOf($definition, 'id'))->deprecated),
    ],
    [
        'property' => 'name',
        'annotation' => '@example',
        'eval' => static function (ClassDefinition $definition): string {
            $examples = annotationsOf(propertyOf($definition, 'name'))->examples;
            return yesNo($examples !== [], $examples !== [] ? 'examples=' . code(implode(', ', $examples)) : null);
        },
    ],
    [
        'property' => 'union',
        'annotation' => 'Free-text docblock (title/description)',
        'eval' => static function (ClassDefinition $definition): string {
            $annotations = annotationsOf(propertyOf($definition, 'union'));
            $hasTitle = $annotations->title !== null && $annotations->title !== '';
            $hasDescription = $annotations->description !== null && $annotations->description !== '';
            return yesNo(
                $hasTitle || $hasDescription,
                implode(', ', array_filter([
                    $hasTitle ? 'title=' . code($annotations->title ?? '') : null,
                    $hasDescription ? 'description' : null,
                ]))
            );
        },
    ],
    [
        'property' => 'objects',
        'annotation' => '@var BasicObject[]',
        'eval' => static function (ClassDefinition $definition): string {
            $valueType = typeLabel(arrayValueType(propertyOf($definition, 'objects')));
            return yesNo($valueType === 'BasicObject', $valueType ? 'items=' . code($valueType) : null);
        },
    ],
    [
        'property' => 'mixedTags',
        'annotation' => '@var array<StatusEnum|string>',
        'eval' => static function (ClassDefinition $definition): string {
            $labels = unionLabels(arrayValueType(propertyOf($definition, 'mixedTags')));
            $hasEnum = \in_array('StatusEnum', $labels, true);
            $hasString = \in_array('string', $labels, true);
            return yesNo($hasEnum && $hasString, $labels ? 'items=' . code(implode('|', $labels)) : null);
        },
    ],
    [
        'property' => 'headers',
        'annotation' => '@var array<string, string> (dictionary)',
        'eval' => static function (ClassDefinition $definition): string {
            $valueType = typeLabel(mapValueType(propertyOf($definition, 'headers')));
            return yesNo($valueType === 'string', $valueType ? 'values=' . code($valueType) : null);
        },
    ],
    [
        'property' => 'settings',
        'annotation' => '@var array{...} (shaped array)',
        'eval' => static function (ClassDefinition $definition): string {
            $shape = inlineObjectShape(propertyOf($definition, 'settings')->getTypeExpr() ?? new BuiltinType('mixed'));
            if ($shape === null) {
                return 'No';
            }

            $keys = array_keys($shape->getProperties());
            sort($keys);
            return yesNo($keys !== [], 'keys=' . code(implode(', ', $keys)));
        },
    ],
    [
        'property' => 'endpoints',
        'annotation' => '@var array<array{...}> (nested shaped array)',
        'eval' => static function (ClassDefinition $definition): string {
            $shape = inlineObjectShape(arrayValueType(propertyOf($definition, 'endpoints')) ?? new BuiltinType('mixed'));
            if ($shape === null) {
                return 'No';
            }

            $keys = array_keys($shape->getProperties());
            sort($keys);
            return yesNo($keys !== [], 'itemKeys=' . code(implode(', ', $keys)));
        },
    ],
    [
        'property' => 'range',
        'annotation' => '@var int<1, 100>',
        'eval' => static function (ClassDefinition $definition): string {
            $constraints = constraintsOf(propertyOf($definition, 'range'));
            return yesNo(
                $constraints->minimum === 1 && $constraints->maximum === 100,
                'min=' . code((string) ($constraints->minimum ?? '')) . ', max=' . code((string) ($constraints->maximum ?? ''))
            );
        },
    ],
    [
        'property' => 'positive',
        'annotation' => '@var positive-int',
        'eval' => static function (ClassDefinition $definition): string {
            $constraints = constraintsOf(propertyOf($definition, 'positive'));
            if ($constraints->exclusiveMinimum !== null) {
                return yesNo(true, 'exclusiveMinimum=' . code((string) $constraints->exclusiveMinimum));
            }
            if ($constraints->minimum !== null) {
                return yesNo(true, 'minimum=' . code((string) $constraints->minimum));
            }

            return 'No';
        },
    ],
    [
        'property' => 'negative',
        'annotation' => '@var negative-int',
        'eval' => static function (ClassDefinition $definition): string {
            $constraints = constraintsOf(propertyOf($definition, 'negative'));
            if ($constraints->exclusiveMaximum !== null) {
                return yesNo(true, 'exclusiveMaximum=' . code((string) $constraints->exclusiveMaximum));
            }
            if ($constraints->maximum !== null) {
                return yesNo(true, 'maximum=' . code((string) $constraints->maximum));
            }

            return 'No';
        },
    ],
    [
        'property' => 'nonEmpty',
        'annotation' => '@var non-empty-string',
        'eval' => static function (ClassDefinition $definition): string {
            $minLength = constraintsOf(propertyOf($definition, 'nonEmpty'))->minLength;
            return yesNo($minLength === 1, 'minLength=' . code((string) ($minLength ?? '')));
        },
    ],
    [
        'property' => 'tags',
        'annotation' => '@var string[] + @var non-empty-array',
        'eval' => static function (ClassDefinition $definition): string {
            $property = propertyOf($definition, 'tags');
            $minItems = constraintsOf($property)->minItems;
            $valueType = typeLabel(arrayValueType($property));
            return yesNo($minItems === 1 || $valueType === 'string', implode(', ', array_filter([
                $minItems === 1 ? 'minItems=' . code((string) $minItems) : null,
                $valueType === 'string' ? 'items=' . code($valueType) : null,
            ])));
        },
    ],
    [
        'property' => 'varUnion',
        'annotation' => '@var string|int|null',
        'eval' => static function (ClassDefinition $definition): string {
            $labels = unionLabels(propertyOf($definition, 'varUnion')->getTypeExpr());
            $hasAll = \in_array('string', $labels, true) && \in_array('int', $labels, true) && \in_array('null', $labels, true);
            return yesNo($hasAll, $labels ? 'types=' . code(implode('|', $labels)) : null);
        },
    ],
    [
        'property' => 'list',
        'annotation' => '@var list<string>',
        'eval' => static function (ClassDefinition $definition): string {
            $valueType = typeLabel(arrayValueType(propertyOf($definition, 'list')));
            return yesNo($valueType === 'string', $valueType ? 'items=' . code($valueType) : null);
        },
    ],
    [
        'property' => 'iterable',
        'annotation' => '@var iterable<int>',
        'eval' => static function (ClassDefinition $definition): string {
            $valueType = typeLabel(arrayValueType(propertyOf($definition, 'iterable')));
            return yesNo($valueType === 'int', $valueType ? 'items=' . code($valueType) : null);
        },
    ],
    [
        'property' => 'nullableDocType',
        'annotation' => '@var ?string',
        'eval' => static function (ClassDefinition $definition): string {
            $labels = unionLabels(propertyOf($definition, 'nullableDocType')->getTypeExpr());
            $hasAll = \in_array('string', $labels, true) && \in_array('null', $labels, true);
            return yesNo($hasAll, $labels ? 'types=' . code(implode('|', $labels)) : null);
        },
    ],
    [
        'property' => 'arrayKey',
        'annotation' => '@var array-key',
        'eval' => static function (ClassDefinition $definition): string {
            $labels = unionLabels(propertyOf($definition, 'arrayKey')->getTypeExpr());
            $hasAll = \in_array('string', $labels, true) && \in_array('int', $labels, true);
            return yesNo($hasAll, $labels ? 'types=' . code(implode('|', $labels)) : null);
        },
    ],
    [
        'property' => 'scalarValue',
        'annotation' => '@var scalar',
        'eval' => static function (ClassDefinition $definition): string {
            $labels = unionLabels(propertyOf($definition, 'scalarValue')->getTypeExpr());
            $hasAll = \in_array('string', $labels, true)
                && \in_array('int', $labels, true)
                && \in_array('float', $labels, true)
                && \in_array('bool', $labels, true);
            return yesNo($hasAll, $labels ? 'types=' . code(implode('|', $labels)) : null);
        },
    ],
    [
        'property' => 'numericText',
        'annotation' => '@var numeric-string',
        'eval' => static function (ClassDefinition $definition): string {
            $constraints = constraintsOf(propertyOf($definition, 'numericText'));
            return yesNo($constraints->pattern !== null, $constraints->pattern !== null ? 'pattern=' . code($constraints->pattern) : null);
        },
    ],
    [
        'property' => 'alwaysTrue',
        'annotation' => '@var true',
        'eval' => static function (ClassDefinition $definition): string {
            $enum = constraintsOf(propertyOf($definition, 'alwaysTrue'))->enum;
            return yesNo($enum === [true], $enum !== [] ? 'enum=' . code(json_encode($enum, JSON_THROW_ON_ERROR)) : null);
        },
    ],
    [
        'property' => 'literalStatus',
        'annotation' => '@var "draft"',
        'eval' => static function (ClassDefinition $definition): string {
            $enum = constraintsOf(propertyOf($definition, 'literalStatus'))->enum;
            return yesNo($enum === ['draft'], $enum !== [] ? 'enum=' . code(json_encode($enum, JSON_THROW_ON_ERROR)) : null);
        },
    ],
    [
        'property' => 'literalCode',
        'annotation' => '@var 123',
        'eval' => static function (ClassDefinition $definition): string {
            $enum = constraintsOf(propertyOf($definition, 'literalCode'))->enum;
            return yesNo($enum === [123], $enum !== [] ? 'enum=' . code(json_encode($enum, JSON_THROW_ON_ERROR)) : null);
        },
    ],
    [
        'property' => 'objectShape',
        'annotation' => '@var object{...}',
        'eval' => static function (ClassDefinition $definition): string {
            $shape = inlineObjectShape(propertyOf($definition, 'objectShape')->getTypeExpr() ?? new BuiltinType('mixed'));
            if ($shape === null) {
                return 'No';
            }

            $keys = array_keys($shape->getProperties());
            sort($keys);
            return yesNo($keys !== [], 'keys=' . code(implode(', ', $keys)));
        },
    ],
    [
        'property' => 'callableSignature',
        'annotation' => '@var callable(int): string',
        'eval' => static function (ClassDefinition $definition): string {
            return yesNo(typeLabel(propertyOf($definition, 'callableSignature')->getTypeExpr()) === 'mixed', 'mixed');
        },
    ],
    [
        'property' => 'classNameString',
        'annotation' => '@var class-string',
        'eval' => static function (ClassDefinition $definition): string {
            return yesNo(typeLabel(propertyOf($definition, 'classNameString')->getTypeExpr()) === 'string', 'type=' . code(typeLabel(propertyOf($definition, 'classNameString')->getTypeExpr()) ?? ''));
        },
    ],
    [
        'property' => 'getterList',
        'annotation' => 'Getter: @return list<string>',
        'eval' => static function (ClassDefinition $definition): string {
            $valueType = typeLabel(arrayValueType(propertyOf($definition, 'getterList')));
            return yesNo($valueType === 'string', $valueType ? 'items=' . code($valueType) : null);
        },
    ],
];

$out = [];
$out[] = '# PHPDoc Enricher capability matrix';
$out[] = '';
$out[] = 'This document is generated by `bin/phpdoc-enricher-capabilities.php` by running each PHPDoc enricher against `tests/Fixtures/PhpDocObject.php` and inspecting the resulting `ClassDefinition` / `PropertyDefinition`.';
$out[] = '';
$out[] = '| Property | Annotation / goal | PhpDocumentor | PHPStan | Symfony PropertyInfo |';
$out[] = '| :--- | :--- | :---: | :---: | :---: |';

foreach ($rows as $row) {
    $cells = [];
    $cells[] = $row['property'];
    $cells[] = $row['annotation'];

    foreach (['PhpDocumentorEnricher', 'PhpStanEnricher', 'SymfonyPhpDocPropertyEnricher'] as $enricherName) {
        $eval = $row['eval'];
        $cells[] = $eval($definitions[$enricherName]);
    }

    $out[] = '| ' . implode(' | ', array_map(static fn(string $cell): string => mdCell($cell), $cells)) . ' |';
}

$out[] = '';

$outputPath = __DIR__ . '/../docs/phpdoc-enricher-capabilities.md';
@mkdir(\dirname($outputPath), 0o777, true);
file_put_contents($outputPath, implode("\n", $out));

echo "Written: {$outputPath}\n";
