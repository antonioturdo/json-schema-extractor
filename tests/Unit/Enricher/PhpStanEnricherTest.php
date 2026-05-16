<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\PhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(PhpStanEnricher::class)]
class PhpStanEnricherTest extends TestCase
{
    use TypeTestHelperTrait;

    private ReflectionDiscoverer $discoverer;
    private PhpStanEnricher $enricher;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionDiscoverer();
        $this->enricher = new PhpStanEnricher();
    }

    public function testEnrichExtractsPropertyMetadata(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $idExpr = $this->requireType($this->requireProperty($definition, 'id')->getType(), 'Expected id to have a type.');
        $idDecorated = $this->findFirstNonNullDecorated($idExpr);
        self::assertNotNull($idDecorated);
        self::assertNotNull($idDecorated->annotations);
        self::assertTrue($idDecorated->annotations->deprecated);

        $nameExpr = $this->requireType($this->requireProperty($definition, 'name')->getType(), 'Expected name to have a type.');
        $nameDecorated = $this->findFirstNonNullDecorated($nameExpr);
        self::assertNotNull($nameDecorated);
        self::assertNotNull($nameDecorated->annotations);
        self::assertSame(['"Park Eun-bin"', '"박은빈"'], $nameDecorated->annotations->examples);

        $unionExpr = $this->requireType($this->requireProperty($definition, 'union')->getType(), 'Expected union to have a type.');
        $unionDecorated = $this->findFirstNonNullDecorated($unionExpr);
        self::assertNotNull($unionDecorated);
        self::assertNotNull($unionDecorated->annotations);
        self::assertNotNull($unionDecorated->annotations->description);
        self::assertStringContainsString('Title of the union field.', $unionDecorated->annotations->description);
        self::assertStringContainsString('Description of the union field.', $unionDecorated->annotations->description);
    }

    public function testEnrichHandlesShapedArrays(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $property = $this->requireProperty($definition, 'favoritePerformance');
        $shape = $this->assertInlineObject($this->requireType($property->getType(), 'Expected favoritePerformance to have a type.'));

        // Check nested properties
        $properties = $shape->getProperties();
        self::assertArrayHasKey('title', $properties);
        self::assertArrayHasKey('role', $properties);
        self::assertArrayHasKey('year', $properties);
        self::assertArrayHasKey('tags', $properties);

        $this->assertBuiltin($this->requireType($properties['title']->getType(), 'Expected favoritePerformance.title to have a type.'), 'string');
        self::assertTrue($properties['title']->isRequired());

        $this->assertBuiltin($this->requireType($properties['role']->getType(), 'Expected favoritePerformance.role to have a type.'), 'string');
        self::assertTrue($properties['role']->isRequired());

        $this->assertBuiltin($this->requireType($properties['year']->getType(), 'Expected favoritePerformance.year to have a type.'), 'int');
        self::assertTrue($properties['year']->isRequired());

        $this->assertArrayOf($this->requireType($properties['tags']->getType(), 'Expected favoritePerformance.tags to have a type.'));
        self::assertFalse($properties['tags']->isRequired());
    }

    public function testEnrichHandlesNestedShapedArraysInCollections(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $property = $this->requireProperty($definition, 'endpoints');
        $expr = $this->unwrapDecorated($this->requireType($property->getType(), 'Expected endpoints to have a type.'));
        self::assertInstanceOf(ArrayType::class, $expr);

        $item = $this->unwrapDecorated($expr->type);
        self::assertInstanceOf(InlineObjectType::class, $item);

        $properties = $item->shape->getProperties();
        self::assertArrayHasKey('url', $properties);
        self::assertArrayHasKey('active', $properties);

        $this->assertBuiltin($this->requireType($properties['url']->getType(), 'Expected endpoints[].url to have a type.'), 'string');
        $this->assertBuiltin($this->requireType($properties['active']->getType(), 'Expected endpoints[].active to have a type.'), 'bool');
    }

    public function testEnrichHandlesIntegerRanges(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $expr = $this->requireType($this->requireProperty($definition, 'range')->getType(), 'Expected range to have a type.');
        $decorated = $this->findFirstNonNullDecorated($expr);
        self::assertNotNull($decorated);
        self::assertSame(1, $decorated->constraints->minimum);
        self::assertSame(100, $decorated->constraints->maximum);
    }

    public function testEnrichHandlesAdvancedTypes(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $birthYear = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'birthYear')->getType());
        $negative = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'negative')->getType());
        $nonEmpty = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'nonEmpty')->getType());
        $tags = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'tags')->getType());

        self::assertNotNull($birthYear);
        self::assertNotNull($negative);
        self::assertNotNull($nonEmpty);
        self::assertNotNull($tags);

        self::assertSame(1, $birthYear->constraints->minimum);
        self::assertSame(-1, $negative->constraints->maximum);
        self::assertSame(1, $nonEmpty->constraints->minLength);
        self::assertSame(1, $tags->constraints->minItems);
    }

    public function testEnrichMergesDecoratedAndNonDecoratedVarTypesForSameProperty(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $tagsExpr = $this->requireType($this->requireProperty($definition, 'tags')->getType(), 'Expected tags to have a type.');
        $tagsDecorated = $this->findFirstNonNullDecorated($tagsExpr);
        self::assertNotNull($tagsDecorated);
        self::assertSame(1, $tagsDecorated->constraints->minItems);

        $inner = $this->unwrapDecorated($tagsDecorated->type);
        self::assertInstanceOf(ArrayType::class, $inner);
        $this->assertBuiltin($inner->type, 'string');
    }

    public function testEnrichHandlesUnionsAndLists(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $promotedListExpr = $this->assertArrayOf($this->requireType($this->requireProperty($definition, 'promotedList')->getType(), 'Expected promotedList to have a type.'));
        $this->assertBuiltin($promotedListExpr->type, 'string');

        $unionExpr = $this->unwrapDecorated($this->requireType($this->requireProperty($definition, 'varUnion')->getType(), 'Expected varUnion to have a type.'));
        self::assertInstanceOf(UnionType::class, $unionExpr);

        $names = [];
        foreach ($unionExpr->types as $subType) {
            $subType = $this->unwrapDecorated($subType);
            if ($subType instanceof BuiltinType) {
                $names[] = $subType->name;
            }
        }
        self::assertContains('string', $names);
        self::assertContains('int', $names);
        self::assertContains('null', $names);

        $listExpr = $this->assertArrayOf($this->requireType($this->requireProperty($definition, 'list')->getType(), 'Expected list to have a type.'));
        $this->assertBuiltin($listExpr->type, 'string');

        $iterExpr = $this->assertArrayOf($this->requireType($this->requireProperty($definition, 'iterable')->getType(), 'Expected iterable to have a type.'));
        $this->assertBuiltin($iterExpr->type, 'int');
    }

    public function testEnrichResolvesAdditionalPhpDocParserTypes(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        self::assertSame(['string', 'null'], $this->collectTypeNames($this->requireProperty($definition, 'nullableDocType')->getType()));
        self::assertSame(['string', 'int'], $this->collectTypeNames($this->requireProperty($definition, 'arrayKey')->getType()));
        self::assertSame(['string', 'int', 'float', 'bool'], $this->collectTypeNames($this->requireProperty($definition, 'scalarValue')->getType()));
        $this->assertBuiltin($this->requireType($this->requireProperty($definition, 'classNameString')->getType(), 'Expected classNameString to have a type.'), 'string');
        $this->assertBuiltin($this->requireType($this->requireProperty($definition, 'callableSignature')->getType(), 'Expected callableSignature to have a type.'), 'mixed');

        $numericText = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'numericText')->getType());
        $alwaysTrue = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'alwaysTrue')->getType());
        $literalStatus = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'literalStatus')->getType());
        $literalCode = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'literalCode')->getType());

        self::assertNotNull($numericText);
        self::assertNotNull($alwaysTrue);
        self::assertNotNull($literalStatus);
        self::assertNotNull($literalCode);

        self::assertSame('^-?(?:\d+|\d*\.\d+)$', $numericText->constraints->pattern);
        self::assertSame([true], $alwaysTrue->constraints->enum);
        self::assertSame(['draft'], $literalStatus->constraints->enum);
        self::assertSame([123], $literalCode->constraints->enum);

        $shape = $this->assertInlineObject($this->requireType($this->requireProperty($definition, 'objectShape')->getType(), 'Expected objectShape to have a type.'));
        $properties = $shape->getProperties();

        self::assertTrue($properties['id']->isRequired());
        self::assertFalse($properties['name']->isRequired());
        $this->assertBuiltin($this->requireType($properties['id']->getType(), 'Expected objectShape.id to have a type.'), 'int');
        $this->assertBuiltin($this->requireType($properties['name']->getType(), 'Expected objectShape.name to have a type.'), 'string');
    }
}
