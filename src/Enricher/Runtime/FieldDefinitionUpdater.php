<?php

namespace Zeusi\JsonSchemaExtractor\Enricher\Runtime;

use Zeusi\JsonSchemaExtractor\Model\Php\FieldDefinitionInterface;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeUtils;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;

final class FieldDefinitionUpdater
{
    public function markRequired(FieldDefinitionInterface $field): void
    {
        $field->setRequired(true);
    }

    public function applyCompatibleDeclaredType(FieldDefinitionInterface $field, Type $type): void
    {
        $currentType = $field->getType();

        if ($currentType === null) {
            $field->setType($type);
            return;
        }

        $mergedType = TypeUtils::mergeCompatibleDeclaredType($currentType, $type);
        if ($mergedType !== null) {
            $field->setType($mergedType);
        }
    }

    public function transformArrayItems(FieldDefinitionInterface $field, callable $transform): void
    {
        $this->transformType(
            $field,
            fn(?Type $type): ?Type => $this->transformArrayItemsType($type, $transform)
        );
    }

    public function applyTitle(FieldDefinitionInterface $field, string $title): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($title): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->title ??= $title;
        });
    }

    public function applyDescription(FieldDefinitionInterface $field, string $description): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($description): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->description ??= $description;
        });
    }

    public function applyDeprecated(FieldDefinitionInterface $field): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->deprecated = true;
        });
    }

    /**
     * @param list<mixed> $examples
     */
    public function applyExamples(FieldDefinitionInterface $field, array $examples): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($examples): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->examples = TypeUtils::mergeExamples($decorated->annotations->examples, $examples);
        });
    }

    public function applyFormat(FieldDefinitionInterface $field, string $format): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($format): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->format = $format;
        });
    }

    public function applyPattern(FieldDefinitionInterface $field, string $pattern): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($pattern): void {
            $decorated->constraints->pattern = $pattern;
        });
    }

    public function applyLength(FieldDefinitionInterface $field, ?int $min, ?int $max): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($min, $max): void {
            if ($min !== null) {
                $decorated->constraints->minLength = $min;
            }
            if ($max !== null) {
                $decorated->constraints->maxLength = $max;
            }
        });
    }

    public function applyRange(FieldDefinitionInterface $field, int|float|null $min, int|float|null $max): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($min, $max): void {
            if ($min !== null) {
                $decorated->constraints->minimum = $min;
            }
            if ($max !== null) {
                $decorated->constraints->maximum = $max;
            }
        });
    }

    public function applyMinimum(FieldDefinitionInterface $field, int|float $minimum): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($minimum): void {
            $decorated->constraints->minimum = $minimum;
        });
    }

    public function applyMaximum(FieldDefinitionInterface $field, int|float $maximum): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($maximum): void {
            $decorated->constraints->maximum = $maximum;
        });
    }

    public function applyExclusiveMinimum(FieldDefinitionInterface $field, int|float $minimum): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($minimum): void {
            $decorated->constraints->exclusiveMinimum = $minimum;
        });
    }

    public function applyExclusiveMaximum(FieldDefinitionInterface $field, int|float $maximum): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($maximum): void {
            $decorated->constraints->exclusiveMaximum = $maximum;
        });
    }

    public function applyMultipleOf(FieldDefinitionInterface $field, int|float $multipleOf): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($multipleOf): void {
            $decorated->constraints->multipleOf = $multipleOf;
        });
    }

    public function applyItemCount(FieldDefinitionInterface $field, ?int $min, ?int $max): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($min, $max): void {
            if ($min !== null) {
                $decorated->constraints->minItems = $min;
            }
            if ($max !== null) {
                $decorated->constraints->maxItems = $max;
            }
        });
    }

    /**
     * @param array<mixed> $values
     */
    public function applyEnum(FieldDefinitionInterface $field, array $values): void
    {
        $this->decorateNonNullBranches($field, function (DecoratedType $decorated) use ($values): void {
            $decorated->constraints->enum = $values;
        });
    }

    /**
     * @param callable(DecoratedType): void $mutate
     */
    private function decorateNonNullBranches(FieldDefinitionInterface $field, callable $mutate): void
    {
        $this->transformType(
            $field,
            fn(?Type $type): ?Type => TypeUtils::decorateNonNullBranches($type, $mutate)
        );
    }

    /**
     * @param callable(?Type): ?Type $transform
     */
    private function transformType(FieldDefinitionInterface $field, callable $transform): void
    {
        $field->setType($transform($field->getType()));
    }

    /**
     * @param callable(FieldDefinitionInterface): void $transform
     */
    private function transformArrayItemsType(?Type $type, callable $transform): ?Type
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof UnionType) {
            $type->types = array_map(
                fn(Type $inner): Type => $this->transformArrayItemsType($inner, $transform) ?? $inner,
                $type->types
            );

            return $type;
        }

        if ($type instanceof DecoratedType) {
            $type->type = $this->transformArrayItemsType($type->type, $transform) ?? $type->type;

            return $type;
        }

        if (!$type instanceof ArrayType) {
            return $type;
        }

        $items = new InlineFieldDefinition('__items');
        $items->setType($type->type);

        $transform($items);

        $type->type = $items->getType() ?? $type->type;

        return $type;
    }

}
