<?php

namespace Zeusi\JsonSchemaExtractor\Serialization\State;

/**
 * The empty traversal state, used by strategies that have no path-dependent behaviour
 * (e.g. {@see \Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy}).
 *
 * Its view key is always empty, so every class resolves to its single canonical view.
 */
final class NeutralProjectionState implements ProjectionState
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function viewKey(): string
    {
        return '';
    }
}
