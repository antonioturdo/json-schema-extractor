<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\InlineObjectType;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionType;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\PhpDocObject;
use Zeusi\JsonSchemaGenerator\Tests\Support\TypeExprTestHelperTrait;

#[CoversClass(PhpStanEnricher::class)]
class PhpStanEnricherTest extends TestCase
{
    use TypeExprTestHelperTrait;

    private ReflectionPropertyDiscoverer $discoverer;
    private PhpStanEnricher $enricher;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionPropertyDiscoverer();
        $this->enricher = new PhpStanEnricher();
    }

    public function testEnrichExtractsPropertyMetadata(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        $idExpr = $this->requireTypeExpr($definition->properties['id']->getTypeExpr(), 'Expected id to have a typeExpr.');
        $idDecorated = $this->findFirstNonNullDecorated($idExpr);
        self::assertNotNull($idDecorated);
        self::assertNotNull($idDecorated->annotations);
        self::assertTrue($idDecorated->annotations->deprecated);

        $nameExpr = $this->requireTypeExpr($definition->properties['name']->getTypeExpr(), 'Expected name to have a typeExpr.');
        $nameDecorated = $this->findFirstNonNullDecorated($nameExpr);
        self::assertNotNull($nameDecorated);
        self::assertNotNull($nameDecorated->annotations);
        self::assertSame(['"Antonio"', '"Turdo"'], $nameDecorated->annotations->examples);

        $unionExpr = $this->requireTypeExpr($definition->properties['union']->getTypeExpr(), 'Expected union to have a typeExpr.');
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

        $this->enricher->enrich($definition, new GenerationContext());

        $property = $definition->properties['settings'];
        $shape = $this->assertInlineObject($this->requireTypeExpr($property->getTypeExpr(), 'Expected settings to have a typeExpr.'));

        // Check nested properties
        $properties = $shape->getProperties();
        self::assertArrayHasKey('id', $properties);
        self::assertArrayHasKey('name', $properties);
        self::assertArrayHasKey('tags', $properties);

        $this->assertBuiltin($this->requireTypeExpr($properties['id']->getTypeExpr(), 'Expected settings.id to have a typeExpr.'), 'int');
        self::assertTrue($properties['id']->isRequired());

        $this->assertBuiltin($this->requireTypeExpr($properties['name']->getTypeExpr(), 'Expected settings.name to have a typeExpr.'), 'string');
        self::assertTrue($properties['name']->isRequired());

        $this->assertArrayOf($this->requireTypeExpr($properties['tags']->getTypeExpr(), 'Expected settings.tags to have a typeExpr.'));
        self::assertFalse($properties['tags']->isRequired());
    }

    public function testEnrichHandlesNestedShapedArraysInCollections(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        $property = $definition->properties['endpoints'];
        $expr = $this->unwrapDecorated($this->requireTypeExpr($property->getTypeExpr(), 'Expected endpoints to have a typeExpr.'));
        self::assertInstanceOf(ArrayType::class, $expr);

        $item = $this->unwrapDecorated($expr->type);
        self::assertInstanceOf(InlineObjectType::class, $item);

        $properties = $item->shape->getProperties();
        self::assertArrayHasKey('url', $properties);
        self::assertArrayHasKey('active', $properties);

        $this->assertBuiltin($this->requireTypeExpr($properties['url']->getTypeExpr(), 'Expected endpoints[].url to have a typeExpr.'), 'string');
        $this->assertBuiltin($this->requireTypeExpr($properties['active']->getTypeExpr(), 'Expected endpoints[].active to have a typeExpr.'), 'bool');
    }

    public function testEnrichHandlesIntegerRanges(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        $expr = $this->requireTypeExpr($definition->properties['range']->getTypeExpr(), 'Expected range to have a typeExpr.');
        $decorated = $this->findFirstNonNullDecorated($expr);
        self::assertNotNull($decorated);
        self::assertSame(1, $decorated->constraints->minimum);
        self::assertSame(100, $decorated->constraints->maximum);
    }

    public function testEnrichHandlesAdvancedTypes(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        $positive = $this->findFirstNonNullDecorated($definition->properties['positive']->getTypeExpr());
        $negative = $this->findFirstNonNullDecorated($definition->properties['negative']->getTypeExpr());
        $range = $this->findFirstNonNullDecorated($definition->properties['range']->getTypeExpr());
        $nonEmpty = $this->findFirstNonNullDecorated($definition->properties['nonEmpty']->getTypeExpr());
        $tags = $this->findFirstNonNullDecorated($definition->properties['tags']->getTypeExpr());

        self::assertNotNull($positive);
        self::assertNotNull($negative);
        self::assertNotNull($range);
        self::assertNotNull($nonEmpty);
        self::assertNotNull($tags);

        self::assertSame(1, $positive->constraints->minimum);
        self::assertSame(-1, $negative->constraints->maximum);
        self::assertSame(1, $range->constraints->minimum);
        self::assertSame(100, $range->constraints->maximum);
        self::assertSame(1, $nonEmpty->constraints->minLength);
        self::assertSame(1, $tags->constraints->minItems);
    }

    public function testEnrichHandlesUnionsAndLists(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        $unionExpr = $this->unwrapDecorated($this->requireTypeExpr($definition->properties['varUnion']->getTypeExpr(), 'Expected varUnion to have a typeExpr.'));
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

        $listExpr = $this->assertArrayOf($this->requireTypeExpr($definition->properties['list']->getTypeExpr(), 'Expected list to have a typeExpr.'));
        $this->assertBuiltin($listExpr->type, 'string');

        $iterExpr = $this->assertArrayOf($this->requireTypeExpr($definition->properties['iterable']->getTypeExpr(), 'Expected iterable to have a typeExpr.'));
        $this->assertBuiltin($iterExpr->type, 'int');
    }

    public function testEnrichResolvesAdditionalPhpDocParserTypes(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        self::assertSame(['string', 'null'], $this->collectTypeNames($definition->properties['nullableDocType']->getTypeExpr()));
        self::assertSame(['string', 'int'], $this->collectTypeNames($definition->properties['arrayKey']->getTypeExpr()));
        self::assertSame(['string', 'int', 'float', 'bool'], $this->collectTypeNames($definition->properties['scalarValue']->getTypeExpr()));
        $this->assertBuiltin($this->requireTypeExpr($definition->properties['classNameString']->getTypeExpr(), 'Expected classNameString to have a typeExpr.'), 'string');
        $this->assertBuiltin($this->requireTypeExpr($definition->properties['callableSignature']->getTypeExpr(), 'Expected callableSignature to have a typeExpr.'), 'mixed');

        $numericText = $this->findFirstNonNullDecorated($definition->properties['numericText']->getTypeExpr());
        $alwaysTrue = $this->findFirstNonNullDecorated($definition->properties['alwaysTrue']->getTypeExpr());
        $literalStatus = $this->findFirstNonNullDecorated($definition->properties['literalStatus']->getTypeExpr());
        $literalCode = $this->findFirstNonNullDecorated($definition->properties['literalCode']->getTypeExpr());

        self::assertNotNull($numericText);
        self::assertNotNull($alwaysTrue);
        self::assertNotNull($literalStatus);
        self::assertNotNull($literalCode);

        self::assertSame('^-?(?:\d+|\d*\.\d+)$', $numericText->constraints->pattern);
        self::assertSame([true], $alwaysTrue->constraints->enum);
        self::assertSame(['draft'], $literalStatus->constraints->enum);
        self::assertSame([123], $literalCode->constraints->enum);

        $shape = $this->assertInlineObject($this->requireTypeExpr($definition->properties['objectShape']->getTypeExpr(), 'Expected objectShape to have a typeExpr.'));
        $properties = $shape->getProperties();

        self::assertTrue($properties['id']->isRequired());
        self::assertFalse($properties['name']->isRequired());
        $this->assertBuiltin($this->requireTypeExpr($properties['id']->getTypeExpr(), 'Expected objectShape.id to have a typeExpr.'), 'int');
        $this->assertBuiltin($this->requireTypeExpr($properties['name']->getTypeExpr(), 'Expected objectShape.name to have a typeExpr.'), 'string');
    }
}
