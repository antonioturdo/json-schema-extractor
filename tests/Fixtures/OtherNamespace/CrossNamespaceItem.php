<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures\OtherNamespace;

/**
 * Lives in a nested namespace so it can only be referenced from the host
 * fixture through a `use` alias (short name), exercising alias resolution.
 */
class CrossNamespaceItem
{
    public int $id;
}
