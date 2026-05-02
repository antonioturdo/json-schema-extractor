<?php

namespace Zeusi\JsonSchemaExtractor\Discoverer;

use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;

/**
 * Pipeline first step. Discovers the basic properties of a class.
 */
interface DiscovererInterface
{
    /**
     * @param class-string $className
     */
    public function discover(string $className): ClassDefinition;
}
