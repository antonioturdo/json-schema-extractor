<?php

namespace Zeusi\JsonSchemaGenerator\Context;

/**
 * Container for generation-time capabilities (e.g. Symfony Serializer options).
 * It can be passed around so enrichers can read the same configuration without guessing.
 */
final class GenerationContext
{
    /** @var array<class-string, object> */
    private array $capabilities = [];

    /**
     * @param array<class-string, object> $capabilities
     */
    public function __construct(array $capabilities = [])
    {
        foreach ($capabilities as $capability) {
            $this->capabilities[$capability::class] = $capability;
        }
    }

    public function with(object $capability): self
    {
        $clone = clone $this;
        $clone->capabilities[$capability::class] = $capability;

        foreach (class_implements($capability) as $interface) {
            $clone->capabilities[$interface] = $capability;
        }

        return $clone;
    }

    /**
     * @template T of object
     * @param class-string<T> $capabilityClass
     * @return ?T
     */
    public function find(string $capabilityClass): ?object
    {
        /** @var ?T $capability */
        $capability = $this->capabilities[$capabilityClass] ?? null;

        return $capability;
    }
}
