<?php

namespace Zeusi\JsonSchemaGenerator\Enricher;

use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\FieldDefinitionInterface;
use Zeusi\JsonSchemaGenerator\Definition\InlineFieldDefinition;
use Zeusi\JsonSchemaGenerator\Definition\InlineObjectDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\DecoratedType;
use Zeusi\JsonSchemaGenerator\Definition\Type\InlineObjectType;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExprUtils;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionType;
use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;

class SymfonyValidationEnricher implements PropertyEnricherInterface
{
    public function __construct(
        private readonly MetadataFactoryInterface $metadataFactory
    ) {}

    public function enrich(ClassDefinition $definition, GenerationContext $context): void
    {
        if (!$this->metadataFactory->hasMetadataFor($definition->className)) {
            return;
        }

        $metadata = $this->metadataFactory->getMetadataFor($definition->className);
        if (!$metadata instanceof ClassMetadataInterface) {
            return;
        }

        foreach ($definition->properties as $property) {
            $propertyName = $property->propertyName;

            // Symfony Validator metadata for properties
            if (!$metadata->hasPropertyMetadata($propertyName)) {
                continue;
            }

            $propertyMetadata = $metadata->getPropertyMetadata($propertyName);
            foreach ($propertyMetadata as $memberMetadata) {
                foreach ($memberMetadata->getConstraints() as $constraint) {
                    $this->mapConstraint($constraint, $property, $definition->className);
                }
            }
        }
    }

    private function mapConstraint(object $constraint, FieldDefinitionInterface $property, string $className): void
    {
        if ($constraint instanceof Constraints\All) {
            $this->mapAllConstraint($constraint, $property);
            return;
        }

        if ($constraint instanceof Constraints\Collection) {
            $this->mapCollectionConstraint($constraint, $property, $className);
            return;
        }

        $this->mapConstraintToProperty($constraint, $property);
    }

    private function mapAllConstraint(Constraints\All $constraint, FieldDefinitionInterface $property): void
    {
        $property->setTypeExpr($this->applyAllConstraintToExpr($property->getTypeExpr(), $constraint));
    }

    private function mapCollectionConstraint(Constraints\Collection $constraint, FieldDefinitionInterface $property, string $className): void
    {
        $inlineObjectId = $className . '::$' . $property->getName() . ' (Collection)';
        $inlineObject = new InlineObjectDefinition(
            id: $inlineObjectId,
            additionalProperties: $constraint->allowExtraFields
        );

        foreach ((array) $constraint->fields as $fieldName => $fieldConstraint) {
            if (!\is_string($fieldName) || $fieldName === '') {
                continue;
            }

            if (!$fieldConstraint instanceof Constraints\Optional && !$fieldConstraint instanceof Constraints\Required) {
                continue;
            }

            $isRequiredKey = $fieldConstraint instanceof Constraints\Required && !$constraint->allowMissingFields;

            $fieldProperty = new InlineFieldDefinition(
                fieldName: $fieldName,
                required: $isRequiredKey
            );

            foreach ((array) $fieldConstraint->constraints as $innerConstraint) {
                if (!\is_object($innerConstraint)) {
                    continue;
                }

                $this->inferTypeExprFromConstraint($innerConstraint, $fieldProperty);
                $this->mapConstraint($innerConstraint, $fieldProperty, $className);
            }

            if ($fieldProperty->getTypeExpr() === null) {
                $fieldProperty->setTypeExpr(new BuiltinType('mixed'));
            }

            $inlineObject->addProperty($fieldProperty);
        }

        $expr = new InlineObjectType($inlineObject);
        $property->setTypeExpr(TypeExprUtils::preserveNullableUnion($property->getTypeExpr(), $expr));
    }

    private function mapConstraintToProperty(object $constraint, FieldDefinitionInterface $property): void
    {
        switch (true) {
            case $constraint instanceof Constraints\NotBlank:
            case $constraint instanceof Constraints\NotNull:
                $property->setRequired(true);
                break;

            case $constraint instanceof Constraints\Email:
                $property->setTypeExpr($this->applyFormatToNonNullBranches($property->getTypeExpr(), \Zeusi\JsonSchemaGenerator\JsonSchema\Format::Email->value));
                break;

            case $constraint instanceof Constraints\Url:
                $property->setTypeExpr($this->applyFormatToNonNullBranches($property->getTypeExpr(), \Zeusi\JsonSchemaGenerator\JsonSchema\Format::Uri->value));
                break;

            case $constraint instanceof Constraints\Uuid:
                $property->setTypeExpr($this->applyFormatToNonNullBranches($property->getTypeExpr(), \Zeusi\JsonSchemaGenerator\JsonSchema\Format::Uuid->value));
                break;

            case $constraint instanceof Constraints\Ip:
                $format = $constraint->version === '6'
                    ? \Zeusi\JsonSchemaGenerator\JsonSchema\Format::IPv6->value
                    : \Zeusi\JsonSchemaGenerator\JsonSchema\Format::IPv4->value;
                $property->setTypeExpr($this->applyFormatToNonNullBranches($property->getTypeExpr(), $format));
                break;

            case $constraint instanceof Constraints\Hostname:
                $property->setTypeExpr($this->applyFormatToNonNullBranches($property->getTypeExpr(), \Zeusi\JsonSchemaGenerator\JsonSchema\Format::Hostname->value));
                break;

            case $constraint instanceof Constraints\Regex:
                $property->setTypeExpr($this->applyPatternToNonNullBranches($property->getTypeExpr(), $constraint->pattern));
                break;

            case $constraint instanceof Constraints\Length:
                $property->setTypeExpr($this->applyLengthToNonNullBranches($property->getTypeExpr(), $constraint->min, $constraint->max));
                break;

            case $constraint instanceof Constraints\Range:
                $property->setTypeExpr($this->applyRangeToNonNullBranches($property->getTypeExpr(), $constraint->min, $constraint->max));
                break;

            case $constraint instanceof Constraints\Positive:
                $property->setTypeExpr($this->applyExclusiveMinimumToNonNullBranches($property->getTypeExpr(), 0));
                break;

            case $constraint instanceof Constraints\PositiveOrZero:
                $property->setTypeExpr($this->applyMinimumToNonNullBranches($property->getTypeExpr(), 0));
                break;

            case $constraint instanceof Constraints\Negative:
                $property->setTypeExpr($this->applyExclusiveMaximumToNonNullBranches($property->getTypeExpr(), 0));
                break;

            case $constraint instanceof Constraints\NegativeOrZero:
                $property->setTypeExpr($this->applyMaximumToNonNullBranches($property->getTypeExpr(), 0));
                break;

            case $constraint instanceof Constraints\GreaterThan:
                $property->setTypeExpr($this->applyExclusiveMinimumToNonNullBranches($property->getTypeExpr(), $constraint->value));
                break;

            case $constraint instanceof Constraints\GreaterThanOrEqual:
                $property->setTypeExpr($this->applyMinimumToNonNullBranches($property->getTypeExpr(), $constraint->value));
                break;

            case $constraint instanceof Constraints\LessThan:
                $property->setTypeExpr($this->applyExclusiveMaximumToNonNullBranches($property->getTypeExpr(), $constraint->value));
                break;

            case $constraint instanceof Constraints\LessThanOrEqual:
                $property->setTypeExpr($this->applyMaximumToNonNullBranches($property->getTypeExpr(), $constraint->value));
                break;

            case $constraint instanceof Constraints\DivisibleBy:
                $property->setTypeExpr($this->applyMultipleOfToNonNullBranches($property->getTypeExpr(), $constraint->value));
                break;

            case $constraint instanceof Constraints\Count:
                $property->setTypeExpr($this->applyCountToNonNullBranches($property->getTypeExpr(), $constraint->min, $constraint->max));
                break;

            case $constraint instanceof Constraints\Choice:
                if (\is_array($constraint->choices)) {
                    $property->setTypeExpr($this->applyEnumToNonNullBranches($property->getTypeExpr(), array_values($constraint->choices)));
                }
                break;
        }
    }

    private function inferTypeExprFromConstraint(object $constraint, FieldDefinitionInterface $property): void
    {
        switch (true) {
            case $constraint instanceof Constraints\Email:
            case $constraint instanceof Constraints\Url:
            case $constraint instanceof Constraints\Uuid:
            case $constraint instanceof Constraints\Ip:
            case $constraint instanceof Constraints\Hostname:
            case $constraint instanceof Constraints\Regex:
            case $constraint instanceof Constraints\Length:
                $property->setTypeExpr($property->getTypeExpr() ?? new BuiltinType('string'));
                break;

            case $constraint instanceof Constraints\Range:
            case $constraint instanceof Constraints\Positive:
            case $constraint instanceof Constraints\PositiveOrZero:
            case $constraint instanceof Constraints\Negative:
            case $constraint instanceof Constraints\NegativeOrZero:
            case $constraint instanceof Constraints\GreaterThan:
            case $constraint instanceof Constraints\GreaterThanOrEqual:
            case $constraint instanceof Constraints\LessThan:
            case $constraint instanceof Constraints\LessThanOrEqual:
            case $constraint instanceof Constraints\DivisibleBy:
                $property->setTypeExpr($property->getTypeExpr() ?? new BuiltinType('float'));
                break;

            case $constraint instanceof Constraints\Count:
                $property->setTypeExpr($property->getTypeExpr() ?? new BuiltinType('array'));
                break;
        }
    }

    private function applyAllConstraintToExpr(?TypeExpr $expr, Constraints\All $constraint): ?TypeExpr
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof UnionType) {
            $expr->types = array_map(fn(TypeExpr $t) => $this->applyAllConstraintToExpr($t, $constraint) ?? $t, $expr->types);
            return $expr;
        }

        if ($expr instanceof DecoratedType) {
            $expr->type = $this->applyAllConstraintToExpr($expr->type, $constraint) ?? $expr->type;
            return $expr;
        }

        if (!$expr instanceof ArrayType) {
            return $expr;
        }

        $items = $expr->type;
        foreach ((array) $constraint->constraints as $innerConstraint) {
            if (!\is_object($innerConstraint)) {
                continue;
            }

            $items = $this->applyConstraintToExpr($items, $innerConstraint);
        }

        $expr->type = $items;
        return $expr;
    }

    private function applyConstraintToExpr(TypeExpr $expr, object $constraint): TypeExpr
    {
        if ($constraint instanceof Constraints\Email) {
            return $this->applyFormatToNonNullBranches($expr, \Zeusi\JsonSchemaGenerator\JsonSchema\Format::Email->value) ?? $expr;
        }
        if ($constraint instanceof Constraints\Regex) {
            return $this->applyPatternToNonNullBranches($expr, $constraint->pattern) ?? $expr;
        }
        if ($constraint instanceof Constraints\Length) {
            return $this->applyLengthToNonNullBranches($expr, $constraint->min, $constraint->max) ?? $expr;
        }
        if ($constraint instanceof Constraints\Choice && \is_array($constraint->choices)) {
            return $this->applyEnumToNonNullBranches($expr, array_values($constraint->choices)) ?? $expr;
        }

        return $expr;
    }

    private function applyFormatToNonNullBranches(?TypeExpr $expr, string $format): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($format): void {
            $decorated->annotations ??= new TypeAnnotations();
            $decorated->annotations->format = $format;
        });
    }

    /**
     * @param array<mixed> $enum
     */
    private function applyEnumToNonNullBranches(?TypeExpr $expr, array $enum): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($enum): void {
            $decorated->constraints->enum = $enum;
        });
    }

    private function applyPatternToNonNullBranches(?TypeExpr $expr, string $pattern): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($pattern): void {
            $decorated->constraints->pattern = $pattern;
        });
    }

    private function applyLengthToNonNullBranches(?TypeExpr $expr, ?int $min, ?int $max): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($min, $max): void {
            if ($min !== null) {
                $decorated->constraints->minLength = $min;
            }
            if ($max !== null) {
                $decorated->constraints->maxLength = $max;
            }
        });
    }

    private function applyRangeToNonNullBranches(?TypeExpr $expr, int|float|null $min, int|float|null $max): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($min, $max): void {
            if ($min !== null) {
                $decorated->constraints->minimum = $min;
            }
            if ($max !== null) {
                $decorated->constraints->maximum = $max;
            }
        });
    }

    private function applyMinimumToNonNullBranches(?TypeExpr $expr, int|float $min): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($min): void {
            $decorated->constraints->minimum = $min;
        });
    }

    private function applyMaximumToNonNullBranches(?TypeExpr $expr, int|float $max): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($max): void {
            $decorated->constraints->maximum = $max;
        });
    }

    private function applyExclusiveMinimumToNonNullBranches(?TypeExpr $expr, int|float $min): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($min): void {
            $decorated->constraints->exclusiveMinimum = $min;
        });
    }

    private function applyExclusiveMaximumToNonNullBranches(?TypeExpr $expr, int|float $max): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($max): void {
            $decorated->constraints->exclusiveMaximum = $max;
        });
    }

    private function applyMultipleOfToNonNullBranches(?TypeExpr $expr, int|float $multiple): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($multiple): void {
            $decorated->constraints->multipleOf = $multiple;
        });
    }

    private function applyCountToNonNullBranches(?TypeExpr $expr, ?int $min, ?int $max): ?TypeExpr
    {
        return TypeExprUtils::decorateNonNullBranches($expr, function (DecoratedType $decorated) use ($min, $max): void {
            if ($min !== null) {
                $decorated->constraints->minItems = $min;
            }
            if ($max !== null) {
                $decorated->constraints->maxItems = $max;
            }
        });
    }

    // TypeExpr decorations/preservation are handled by {@see TypeExprUtils}.
}
