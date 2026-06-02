<?php

namespace Zeusi\JsonSchemaExtractor\Serialization\State;

/**
 * Symfony Serializer traversal state: the `AbstractNormalizer::ATTRIBUTES` slice that
 * applies to the class currently being projected.
 *
 * The slice mixes leaf attribute names (list entries) and nested views (key => sub-slice),
 * e.g. `['name', 'address' => ['city']]`. An empty slice is still a narrowing: it serializes
 * no attributes (an empty object), distinct from the canonical view (no narrowing at all).
 */
final class SymfonyProjectionState implements ProjectionState
{
    /**
     * @param array<array-key, mixed> $attributesView
     */
    public function __construct(
        private readonly array $attributesView,
    ) {}

    /**
     * @return array<array-key, mixed>
     */
    public function attributesView(): array
    {
        return $this->attributesView;
    }

    public function viewKey(): string
    {
        // A SymfonyProjectionState always represents an ATTRIBUTES narrowing — even an empty
        // slice (which serializes no attributes) is a distinct view, never the canonical one.
        return substr(hash('sha256', $this->canonicalize($this->attributesView)), 0, 12);
    }

    /**
     * Stable string fingerprint of the slice, independent of attribute ordering.
     *
     * @param array<array-key, mixed> $view
     */
    private function canonicalize(array $view): string
    {
        $leaves = [];
        $nested = [];
        foreach ($view as $key => $value) {
            if (\is_int($key) && \is_string($value)) {
                $leaves[] = $value;
            } elseif (\is_array($value)) {
                $nested[(string) $key] = $this->canonicalize($value);
            }
        }

        sort($leaves);
        ksort($nested);

        return json_encode(['leaves' => $leaves, 'nested' => $nested], \JSON_THROW_ON_ERROR);
    }
}
