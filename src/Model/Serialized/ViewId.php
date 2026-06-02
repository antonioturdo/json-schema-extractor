<?php

namespace Zeusi\JsonSchemaExtractor\Model\Serialized;

/**
 * Identity of a serialized view: a class plus an optional view fingerprint.
 *
 * The fingerprint distinguishes different serialized projections of the same class
 * (e.g. context-specific views). It is empty for the canonical view; serialization
 * strategies populate it when a class is projected under a narrowing context.
 */
final class ViewId
{
    public function __construct(
        public readonly string $className,
        public readonly string $viewKey = '',
    ) {}

    /**
     * Stable string key for registry lookups.
     */
    public function key(): string
    {
        return $this->viewKey === '' ? $this->className : $this->className . '@' . $this->viewKey;
    }
}
