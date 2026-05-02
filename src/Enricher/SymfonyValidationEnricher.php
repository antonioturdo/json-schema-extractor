<?php

namespace Zeusi\JsonSchemaExtractor\Enricher;

use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Format;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\FieldDefinitionInterface;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;

class SymfonyValidationEnricher implements EnricherInterface
{
    public function __construct(
        private readonly MetadataFactoryInterface $metadataFactory
    ) {}

    public function enrich(ClassDefinition $definition, ExtractionContext $context, EnrichmentRuntime $runtime): void
    {
        if (!$this->metadataFactory->hasMetadataFor($definition->getClassName())) {
            return;
        }

        $metadata = $this->metadataFactory->getMetadataFor($definition->getClassName());
        if (!$metadata instanceof ClassMetadataInterface) {
            return;
        }

        foreach ($definition->getProperties() as $property) {
            $propertyName = $property->getName();

            // Symfony Validator metadata for properties
            if (!$metadata->hasPropertyMetadata($propertyName)) {
                continue;
            }

            $propertyMetadata = $metadata->getPropertyMetadata($propertyName);
            foreach ($propertyMetadata as $memberMetadata) {
                foreach ($memberMetadata->getConstraints() as $constraint) {
                    $this->mapConstraint($constraint, $property, $definition->getClassName(), $runtime);
                }
            }
        }
    }

    private function mapConstraint(object $constraint, FieldDefinitionInterface $property, string $className, EnrichmentRuntime $runtime): void
    {
        if ($constraint instanceof Constraints\All) {
            $this->mapAllConstraint($constraint, $property, $runtime);
            return;
        }

        if ($constraint instanceof Constraints\Collection) {
            $this->mapCollectionConstraint($constraint, $property, $className, $runtime);
            return;
        }

        if ($constraint instanceof Constraints\NotBlank || $constraint instanceof Constraints\NotNull) {
            $runtime->fieldDefinitionUpdater->markRequired($property);
            return;
        }

        $this->applyConstraintToField($constraint, $property, $runtime);
    }

    private function mapAllConstraint(Constraints\All $constraint, FieldDefinitionInterface $property, EnrichmentRuntime $runtime): void
    {
        $runtime->fieldDefinitionUpdater->transformArrayItems(
            $property,
            function (FieldDefinitionInterface $items) use ($constraint, $runtime): void {
                foreach ((array) $constraint->constraints as $innerConstraint) {
                    if (!\is_object($innerConstraint)) {
                        continue;
                    }

                    $this->applyConstraintToField($innerConstraint, $items, $runtime);
                }
            }
        );
    }

    private function mapCollectionConstraint(Constraints\Collection $constraint, FieldDefinitionInterface $property, string $className, EnrichmentRuntime $runtime): void
    {
        $inlineObject = new InlineObjectDefinition(
            id: $className . '::$' . $property->getName() . ' (Collection)'
        );
        $inlineObject->setAdditionalProperties($constraint->allowExtraFields);

        foreach ((array) $constraint->fields as $fieldName => $fieldConstraint) {
            if (!\is_string($fieldName) || $fieldName === '') {
                continue;
            }

            // Symfony normalizes Collection::fields entries in Collection::initializeNestedConstraints():
            // anything that is not already Optional/Required is wrapped in Required.
            if (!$fieldConstraint instanceof Constraints\Optional && !$fieldConstraint instanceof Constraints\Required) {
                continue;
            }

            $isRequiredKey = $fieldConstraint instanceof Constraints\Required && !$constraint->allowMissingFields;

            $fieldProperty = new InlineFieldDefinition(
                fieldName: $fieldName,
                required: $isRequiredKey
            );
            $fieldProperty->setType(new UnknownType());

            foreach ((array) $fieldConstraint->constraints as $innerConstraint) {
                if (!\is_object($innerConstraint)) {
                    continue;
                }

                $this->mapConstraint($innerConstraint, $fieldProperty, $className, $runtime);
            }

            $inlineObject->addProperty($fieldProperty);
        }

        $expr = new InlineObjectType($inlineObject);
        $runtime->fieldDefinitionUpdater->applyCompatibleDeclaredType(
            $property,
            $expr
        );
    }

    private function applyConstraintToField(object $constraint, FieldDefinitionInterface $property, EnrichmentRuntime $runtime): void
    {
        if ($constraint instanceof Constraints\Email) {
            $runtime->fieldDefinitionUpdater->applyFormat($property, Format::Email->value);
            return;
        }
        if ($constraint instanceof Constraints\Url) {
            $runtime->fieldDefinitionUpdater->applyFormat($property, Format::Uri->value);
            return;
        }
        if ($constraint instanceof Constraints\Uuid) {
            $runtime->fieldDefinitionUpdater->applyFormat($property, Format::Uuid->value);
            return;
        }
        if ($constraint instanceof Constraints\Ip) {
            $format = $constraint->version === '6'
                ? Format::IPv6->value
                : Format::IPv4->value;

            $runtime->fieldDefinitionUpdater->applyFormat($property, $format);
            return;
        }
        if ($constraint instanceof Constraints\Hostname) {
            $runtime->fieldDefinitionUpdater->applyFormat($property, Format::Hostname->value);
            return;
        }
        if ($constraint instanceof Constraints\Regex && \is_string($constraint->pattern)) {
            $runtime->fieldDefinitionUpdater->applyPattern($property, $constraint->pattern);
            return;
        }
        if ($constraint instanceof Constraints\Length) {
            $runtime->fieldDefinitionUpdater->applyLength($property, $constraint->min, $constraint->max);
            return;
        }
        if ($constraint instanceof Constraints\Range) {
            $runtime->fieldDefinitionUpdater->applyRange($property, $constraint->min, $constraint->max);
            return;
        }
        if ($constraint instanceof Constraints\Positive) {
            $runtime->fieldDefinitionUpdater->applyExclusiveMinimum($property, 0);
            return;
        }
        if ($constraint instanceof Constraints\PositiveOrZero) {
            $runtime->fieldDefinitionUpdater->applyMinimum($property, 0);
            return;
        }
        if ($constraint instanceof Constraints\Negative) {
            $runtime->fieldDefinitionUpdater->applyExclusiveMaximum($property, 0);
            return;
        }
        if ($constraint instanceof Constraints\NegativeOrZero) {
            $runtime->fieldDefinitionUpdater->applyMaximum($property, 0);
            return;
        }
        if ($constraint instanceof Constraints\GreaterThan) {
            $runtime->fieldDefinitionUpdater->applyExclusiveMinimum($property, $constraint->value);
            return;
        }
        if ($constraint instanceof Constraints\GreaterThanOrEqual) {
            $runtime->fieldDefinitionUpdater->applyMinimum($property, $constraint->value);
            return;
        }
        if ($constraint instanceof Constraints\LessThan) {
            $runtime->fieldDefinitionUpdater->applyExclusiveMaximum($property, $constraint->value);
            return;
        }
        if ($constraint instanceof Constraints\LessThanOrEqual) {
            $runtime->fieldDefinitionUpdater->applyMaximum($property, $constraint->value);
            return;
        }
        if ($constraint instanceof Constraints\DivisibleBy) {
            $runtime->fieldDefinitionUpdater->applyMultipleOf($property, $constraint->value);
            return;
        }
        if ($constraint instanceof Constraints\Count) {
            $runtime->fieldDefinitionUpdater->applyItemCount($property, $constraint->min, $constraint->max);
            return;
        }
        if ($constraint instanceof Constraints\Choice && \is_array($constraint->choices)) {
            $runtime->fieldDefinitionUpdater->applyEnum($property, array_values($constraint->choices));
        }
    }
}
