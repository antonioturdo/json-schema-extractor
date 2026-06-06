<?php

namespace Zeusi\JsonSchemaExtractor\Model\Serialized;

/**
 * The fully resolved result of the projection phase: every reachable class projected
 * into a serialized payload, plus the {@see ViewId} of the root.
 *
 * The extractor builds this; the mapper folds it into JSON Schema without re-entering
 * the extraction pipeline. Payloads reference each other through class-backed types,
 * which are resolved against this same projection.
 */
final class SerializedProjection
{
    /**
     * @param array<string, SerializedPayloadDefinition> $views Keyed by {@see ViewId::key()}
     */
    public function __construct(
        private readonly ViewId $root,
        private readonly array $views,
    ) {}

    public function root(): ViewId
    {
        return $this->root;
    }

    public function rootPayload(): SerializedPayloadDefinition
    {
        return $this->get($this->root);
    }

    public function has(ViewId $id): bool
    {
        return isset($this->views[$id->key()]);
    }

    /**
     * @throws \LogicException
     */
    public function get(ViewId $id): SerializedPayloadDefinition
    {
        return $this->views[$id->key()]
            ?? throw new \LogicException(\sprintf('No serialized payload registered for view "%s".', $id->key()));
    }
}
