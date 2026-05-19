<?php

namespace Zeusi\JsonSchemaExtractor\Enricher\Runtime;

use Zeusi\JsonSchemaExtractor\Model\Php\MethodDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeUtils;

final class MethodDefinitionUpdater
{
    public function applyCompatibleDeclaredReturnType(MethodDefinition $method, Type $type): void
    {
        $currentType = $method->getReturnType();

        if ($currentType === null) {
            $method->setReturnType($type);
            return;
        }

        $mergedType = TypeUtils::mergeCompatibleDeclaredType($currentType, $type);
        if ($mergedType !== null) {
            $method->setReturnType($mergedType);
        }
    }
}
