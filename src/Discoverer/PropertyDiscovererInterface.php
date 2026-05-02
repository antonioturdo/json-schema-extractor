<?php

namespace Zeusi\JsonSchemaGenerator\Discoverer;

use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;

/**
 * Entrata della pipeline: scopre le proprietà base di una classe.
 */
interface PropertyDiscovererInterface
{
    /**
     * @param class-string $className
     */
    public function discover(string $className): ClassDefinition;
}
