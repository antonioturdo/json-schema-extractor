<?php

namespace Zeusi\JsonSchemaGenerator\Context;

/**
 * Stack of classes currently being processed to detect and handle recursion.
 *
 * @internal
 */
final class ProcessingStackContext
{
    public function __construct(
        /**
         * @var array<class-string>
         */
        public readonly array $classes = []
    ) {}

    /**
     * @param class-string $class
     */
    public function has(string $class): bool
    {
        return \in_array($class, $this->classes, true);
    }

    /**
     * @param class-string $class
     */
    public function pushed(string $class): self
    {
        return new self([...$this->classes, $class]);
    }
}
