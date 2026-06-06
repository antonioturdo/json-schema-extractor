<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Serialization\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Serialization\State\SymfonyProjectionState;

#[CoversClass(SymfonyProjectionState::class)]
final class SymfonyProjectionStateTest extends TestCase
{
    public function testEmptyViewHasAStableNonEmptyKeyDistinctFromNarrowedViews(): void
    {
        $emptyKey = (new SymfonyProjectionState([]))->viewKey();

        // An empty slice is a real (empty) view, not the canonical one: it has its own key.
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $emptyKey);
        self::assertSame($emptyKey, (new SymfonyProjectionState([]))->viewKey());
        self::assertNotSame($emptyKey, (new SymfonyProjectionState(['name']))->viewKey());
    }

    public function testViewKeyIsAStableShortHashForNonEmptyView(): void
    {
        $key = (new SymfonyProjectionState(['name']))->viewKey();

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $key);
        // Deterministic across instances.
        self::assertSame($key, (new SymfonyProjectionState(['name']))->viewKey());
    }

    public function testViewKeyIsIndependentOfLeafOrder(): void
    {
        self::assertSame(
            (new SymfonyProjectionState(['name', 'address']))->viewKey(),
            (new SymfonyProjectionState(['address', 'name']))->viewKey(),
        );
    }

    public function testViewKeyIsIndependentOfNestedKeyOrder(): void
    {
        self::assertSame(
            (new SymfonyProjectionState(['a' => ['x'], 'b' => ['y']]))->viewKey(),
            (new SymfonyProjectionState(['b' => ['y'], 'a' => ['x']]))->viewKey(),
        );
    }

    public function testDifferentViewsProduceDifferentKeys(): void
    {
        self::assertNotSame(
            (new SymfonyProjectionState(['name']))->viewKey(),
            (new SymfonyProjectionState(['address']))->viewKey(),
        );
    }

    public function testLeafAndNestedAttributeProduceDifferentKeys(): void
    {
        self::assertNotSame(
            (new SymfonyProjectionState(['address']))->viewKey(),
            (new SymfonyProjectionState(['address' => ['city']]))->viewKey(),
        );
    }

    public function testAttributesViewReturnsTheSlice(): void
    {
        $view = ['name', 'address' => ['city']];

        self::assertSame($view, (new SymfonyProjectionState($view))->attributesView());
    }
}
