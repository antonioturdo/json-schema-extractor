<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

use Zeusi\JsonSchemaExtractor\Tests\Fixtures\OtherNamespace\CrossNamespaceItem;

/**
 * Host object whose `@var` annotation references a class imported from another
 * namespace via a `use` alias (short name only).
 */
class CrossNamespaceHost
{
    /** @var CrossNamespaceItem[] */
    public array $items;
}
