<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\EnricherInterface;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CrossNamespaceHost;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\OtherNamespace\CrossNamespaceItem;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

/**
 * A `@var Foo[]` annotation referencing a class imported from another namespace
 * via a `use` alias (short name only). `PhpStanEnricher` alone cannot resolve the
 * alias, but composing it with `PhpDocumentorEnricher` does — and, thanks to the
 * non-destructive merge, it works regardless of the order the enrichers run in.
 */
#[CoversNothing]
class ComposedEnrichersCrossNamespaceTest extends TestCase
{
    use TypeTestHelperTrait;

    private ReflectionDiscoverer $discoverer;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionDiscoverer();
    }

    public function testPhpStanThenPhpDocumentorResolvesCrossNamespaceAlias(): void
    {
        self::assertSame(
            [CrossNamespaceItem::class],
            $this->resolveItemsType(new PhpStanEnricher(), new PhpDocumentorEnricher())
        );
    }

    public function testPhpDocumentorThenPhpStanResolvesCrossNamespaceAlias(): void
    {
        self::assertSame(
            [CrossNamespaceItem::class],
            $this->resolveItemsType(new PhpDocumentorEnricher(), new PhpStanEnricher())
        );
    }

    /**
     * @return list<string>
     */
    private function resolveItemsType(EnricherInterface ...$enrichers): array
    {
        $definition = $this->discoverer->discover(CrossNamespaceHost::class);
        $extractionContext = new ExtractionContext();
        $runtime = new EnrichmentRuntime();

        foreach ($enrichers as $enricher) {
            $enricher->enrich($definition, $extractionContext, $runtime);
        }

        $array = $this->assertArrayOf(
            $this->requireType(
                $this->requireProperty($definition, 'items')->getType(),
                'Expected items to have a type.'
            )
        );

        return $this->collectTypeNames($array->type);
    }
}
