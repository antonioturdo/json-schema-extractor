<?php

namespace Zeusi\JsonSchemaExtractor\Model\Type;

use Zeusi\JsonSchemaExtractor\Model\Php\InlineFieldDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\InlineObjectDefinition;

/**
 * @internal
 */
final class InlineObjectTypeUtils
{
    public static function mergeInlineObjectTypes(?Type $current, Type $next): Type
    {
        $currentInlineObject = self::extractInlineObjectDefinition($current);
        $nextInlineObject = self::extractInlineObjectDefinition($next);

        if ($currentInlineObject !== null && $nextInlineObject !== null) {
            return TypeUtils::preserveNullableUnion($current, new InlineObjectType(
                self::mergeInlineObjectDefinitions($currentInlineObject, $nextInlineObject)
            ));
        }

        return $next;
    }

    private static function extractInlineObjectDefinition(?Type $expr): ?InlineObjectDefinition
    {
        $expr = TypeUtils::unwrapDecorated($expr);

        return $expr instanceof InlineObjectType ? $expr->shape : null;
    }

    private static function mergeInlineObjectDefinitions(InlineObjectDefinition $target, InlineObjectDefinition $source): InlineObjectDefinition
    {
        $target->setTitle($target->getTitle() ?? $source->getTitle());
        $target->setDescription($target->getDescription() ?? $source->getDescription());
        $target->setAdditionalProperties($target->getAdditionalProperties() ?? $source->getAdditionalProperties());

        foreach ($source->getProperties() as $sourceProperty) {
            $targetProperty = $target->getOrCreateProperty(new InlineFieldDefinition($sourceProperty->getName()));
            $targetProperty->setRequired($targetProperty->isRequired() || $sourceProperty->isRequired());
            $targetProperty->setType(self::mergeInlineFieldType(
                $targetProperty->getType(),
                $sourceProperty->getType()
            ));
        }

        return $target;
    }

    private static function mergeInlineFieldType(?Type $target, ?Type $source): ?Type
    {
        if ($target !== null && self::isUnknownType($source)) {
            return TypeUtils::mergeTypeConstraintsAndAnnotations($source, $target);
        }

        return TypeUtils::mergeTypeConstraintsAndAnnotations($target, $source);
    }

    private static function isUnknownType(?Type $expr): bool
    {
        $expr = TypeUtils::unwrapDecorated($expr);

        return $expr instanceof UnknownType;
    }
}
