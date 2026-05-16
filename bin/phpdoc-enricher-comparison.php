#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\FieldDefinitionInterface;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\PhpDocObject;

function propertyOf(ClassDefinition $definition, string $propertyName): FieldDefinitionInterface
{
    $property = $definition->getProperty($propertyName);
    if ($property === null) {
        throw new RuntimeException(\sprintf('Property "%s" not found in %s.', $propertyName, $definition->getClassName()));
    }

    return $property;
}

function unwrapDecorated(Type $expr): Type
{
    while ($expr instanceof DecoratedType) {
        $expr = $expr->type;
    }

    return $expr;
}

function findFirstDecorated(?Type $expr): ?DecoratedType
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
    $decorated = findFirstDecorated($property->getType());
    if ($decorated === null || $decorated->annotations === null) {
        return new TypeAnnotations();
    }

    return $decorated->annotations;
}

function constraintsOf(FieldDefinitionInterface $property): TypeConstraints
{
    $decorated = findFirstDecorated($property->getType());
    if ($decorated === null) {
        return new TypeConstraints();
    }

    return $decorated->constraints;
}

function patternOf(FieldDefinitionInterface $property): ?string
{
    return constraintsOf($property)->pattern;
}

function arrayValueType(FieldDefinitionInterface $property): ?Type
{
    $expr = $property->getType();
    if ($expr === null) {
        return null;
    }

    $expr = unwrapDecorated($expr);
    if (!$expr instanceof ArrayType) {
        return null;
    }

    return $expr->type;
}

function mapValueType(FieldDefinitionInterface $property): ?Type
{
    $expr = $property->getType();
    if ($expr === null) {
        return null;
    }

    $expr = unwrapDecorated($expr);
    if (!$expr instanceof MapType) {
        return null;
    }

    return $expr->type;
}

function inlineObjectShape(Type $expr): ?InlineObjectDefinition
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

function typeLabel(?Type $expr): ?string
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
function unionLabels(?Type $expr): array
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

$discoverer = new ReflectionDiscoverer(setTitleFromClassName: false);
$context = new ExtractionContext();
$runtime = new EnrichmentRuntime();

$enrichers = [
    'PhpDocumentorEnricher' => new PhpDocumentorEnricher(),
    'PhpStanEnricher' => new PhpStanEnricher(),
];

/** @var array<string, ClassDefinition> $definitions */
$definitions = [];
foreach ($enrichers as $name => $enricher) {
    $definition = $discoverer->discover(PhpDocObject::class);
    $enricher->enrich($definition, $context, $runtime);
    $definitions[$name] = $definition;
}

$rows = [
    [
        'property' => '(class)',
        'annotation' => 'Class PHPDoc summary/description',
        'eval' => static function (ClassDefinition $definition): string {
            $title = $definition->getTitle();
            $description = $definition->getDescription();
            $hasTitle = $title !== null && $title !== '';
            $hasDescription = $description !== null && $description !== '';
            return yesNo($hasTitle || $hasDescription, implode(', ', array_filter([
                $hasTitle ? 'title=' . code($title ?? '') : null,
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
        'property' => 'favoritePerformance',
        'annotation' => '@var array{...} (shaped array)',
        'eval' => static function (ClassDefinition $definition): string {
            $shape = inlineObjectShape(propertyOf($definition, 'favoritePerformance')->getType() ?? new BuiltinType('mixed'));
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
        'property' => 'lowercase',
        'annotation' => '@var lowercase-string',
        'eval' => static function (ClassDefinition $definition): string {
            $pattern = patternOf(propertyOf($definition, 'lowercase'));
            return yesNo($pattern !== null, $pattern !== null ? 'pattern=' . code($pattern) : 'type=' . code(typeLabel(propertyOf($definition, 'lowercase')->getType()) ?? ''));
        },
    ],
    [
        'property' => 'nonEmptyLowercase',
        'annotation' => '@var non-empty-lowercase-string',
        'eval' => static function (ClassDefinition $definition): string {
            $property = propertyOf($definition, 'nonEmptyLowercase');
            $constraints = constraintsOf($property);
            return yesNo(
                $constraints->minLength === 1 && $constraints->pattern !== null,
                implode(', ', array_filter([
                    $constraints->minLength === 1 ? 'minLength=' . code((string) $constraints->minLength) : null,
                    $constraints->pattern !== null ? 'pattern=' . code($constraints->pattern) : null,
                ]))
            );
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
            $labels = unionLabels(propertyOf($definition, 'varUnion')->getType());
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
            $labels = unionLabels(propertyOf($definition, 'nullableDocType')->getType());
            $hasAll = \in_array('string', $labels, true) && \in_array('null', $labels, true);
            return yesNo($hasAll, $labels ? 'types=' . code(implode('|', $labels)) : null);
        },
    ],
    [
        'property' => 'arrayKey',
        'annotation' => '@var array-key',
        'eval' => static function (ClassDefinition $definition): string {
            $labels = unionLabels(propertyOf($definition, 'arrayKey')->getType());
            $hasAll = \in_array('string', $labels, true) && \in_array('int', $labels, true);
            return yesNo($hasAll, $labels ? 'types=' . code(implode('|', $labels)) : null);
        },
    ],
    [
        'property' => 'scalarValue',
        'annotation' => '@var scalar',
        'eval' => static function (ClassDefinition $definition): string {
            $labels = unionLabels(propertyOf($definition, 'scalarValue')->getType());
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
            $shape = inlineObjectShape(propertyOf($definition, 'objectShape')->getType() ?? new BuiltinType('mixed'));
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
            return yesNo(typeLabel(propertyOf($definition, 'callableSignature')->getType()) === 'mixed', 'mixed');
        },
    ],
    [
        'property' => 'classNameString',
        'annotation' => '@var class-string',
        'eval' => static function (ClassDefinition $definition): string {
            return yesNo(typeLabel(propertyOf($definition, 'classNameString')->getType()) === 'string', 'type=' . code(typeLabel(propertyOf($definition, 'classNameString')->getType()) ?? ''));
        },
    ],
    [
        'property' => 'literalText',
        'annotation' => '@var literal-string',
        'eval' => static function (ClassDefinition $definition): string {
            return yesNo(typeLabel(propertyOf($definition, 'literalText')->getType()) === 'string', 'type=' . code(typeLabel(propertyOf($definition, 'literalText')->getType()) ?? ''));
        },
    ],
    [
        'property' => 'enumKey',
        'annotation' => '@var key-of<StatusEnum>',
        'eval' => static function (ClassDefinition $definition): string {
            $labels = unionLabels(propertyOf($definition, 'enumKey')->getType());
            $hasAll = \in_array('string', $labels, true) && \in_array('int', $labels, true);
            return yesNo($hasAll, $labels ? 'types=' . code(implode('|', $labels)) : 'type=' . code(typeLabel(propertyOf($definition, 'enumKey')->getType()) ?? ''));
        },
    ],
    [
        'property' => 'enumValue',
        'annotation' => '@var value-of<StatusEnum>',
        'eval' => static function (ClassDefinition $definition): string {
            return yesNo(typeLabel(propertyOf($definition, 'enumValue')->getType()) === 'mixed', 'type=' . code(typeLabel(propertyOf($definition, 'enumValue')->getType()) ?? ''));
        },
    ],
    [
        'property' => 'permissionsMask',
        'annotation' => '@var int-mask<1, 2, 4>',
        'eval' => static function (ClassDefinition $definition): string {
            return yesNo(typeLabel(propertyOf($definition, 'permissionsMask')->getType()) === 'int', 'type=' . code(typeLabel(propertyOf($definition, 'permissionsMask')->getType()) ?? ''));
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
$out[] = 'This document is generated by `bin/phpdoc-enricher-comparison.php` by running each PHPDoc enricher against `tests/Fixtures/PhpDocObject.php` and inspecting the resulting class and field definitions.';
$out[] = '';
$out[] = '| Property | Annotation / goal | PhpDocumentor | PHPStan |';
$out[] = '| :--- | :--- | :---: | :---: |';

foreach ($rows as $row) {
    $cells = [];
    $cells[] = $row['property'];
    $cells[] = $row['annotation'];

    foreach (['PhpDocumentorEnricher', 'PhpStanEnricher'] as $enricherName) {
        $eval = $row['eval'];
        $cells[] = $eval($definitions[$enricherName]);
    }

    $out[] = '| ' . implode(' | ', array_map(static fn(string $cell): string => mdCell($cell), $cells)) . ' |';
}

$out[] = '';

$outputPath = __DIR__ . '/../docs/enrichers/phpdoc-enricher-comparison.md';
@mkdir(\dirname($outputPath), 0o777, true);
file_put_contents($outputPath, implode("\n", $out));

echo "Written: {$outputPath}\n";
