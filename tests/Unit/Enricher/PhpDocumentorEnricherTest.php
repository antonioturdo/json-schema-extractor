<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor\AbstractPhpDocumentorTypeMapper;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor\PhpDocumentorTypeMapperV5;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor\PhpDocumentorTypeMapperV6;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\PhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(PhpDocumentorEnricher::class)]
#[CoversClass(AbstractPhpDocumentorTypeMapper::class)]
#[CoversClass(PhpDocumentorTypeMapperV5::class)]
#[CoversClass(PhpDocumentorTypeMapperV6::class)]
class PhpDocumentorEnricherTest extends TestCase
{
    use TypeTestHelperTrait;

    private ReflectionDiscoverer $discoverer;
    private PhpDocumentorEnricher $enricher;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionDiscoverer();
        $this->enricher = new PhpDocumentorEnricher();
    }

    public function testEnrichExtractsClassMetadata(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);
        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        self::assertSame('Short summary for PhpDocObject.', $definition->getTitle());
        $description = $definition->getDescription();
        self::assertNotNull($description);
        self::assertStringContainsString('long description of the PHPDoc object', $description);
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
        self::assertSame('Title of the union field.', $unionDecorated->annotations->title);
        self::assertSame('Description of the union field.', $unionDecorated->annotations->description);
    }

    public function testEnrichResolvesGenericCollections(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $promotedListExpr = $this->unwrapDecorated($this->requireType($this->requireProperty($definition, 'promotedList')->getType(), 'Expected promotedList to have a type.'));
        self::assertInstanceOf(ArrayType::class, $promotedListExpr);
        $this->assertBuiltin($promotedListExpr->type, 'string');

        $objectsExpr = $this->unwrapDecorated($this->requireType($this->requireProperty($definition, 'objects')->getType(), 'Expected objects to have a type.'));
        self::assertInstanceOf(ArrayType::class, $objectsExpr);
        self::assertInstanceOf(ClassLikeType::class, $objectsExpr->type);
        self::assertSame('Zeusi\\JsonSchemaExtractor\\Tests\\Fixtures\\BasicObject', $objectsExpr->type->name);

        $mixedExpr = $this->unwrapDecorated($this->requireType($this->requireProperty($definition, 'mixedTags')->getType(), 'Expected mixedTags to have a type.'));
        self::assertInstanceOf(ArrayType::class, $mixedExpr);
        $inner = $this->unwrapDecorated($mixedExpr->type);
        self::assertInstanceOf(UnionType::class, $inner);

        $hasString = false;
        $hasEnumBranch = false;
        foreach ($inner->types as $subType) {
            if ($subType instanceof EnumType) {
                self::assertSame('Zeusi\\JsonSchemaExtractor\\Tests\\Fixtures\\StatusEnum', $subType->className);
                $hasEnumBranch = true;
                continue;
            }

            $subType = $this->unwrapDecorated($subType);
            if ($subType instanceof BuiltinType && $subType->name === 'string') {
                $hasString = true;
            }
        }

        self::assertTrue($hasEnumBranch);
        self::assertTrue($hasString);

        $favoritePerformanceShape = $this->assertInlineObject($this->requireType($this->requireProperty($definition, 'favoritePerformance')->getType(), 'Expected favoritePerformance to have a type.'));
        $favoritePerformanceProperties = $favoritePerformanceShape->getProperties();

        self::assertTrue($favoritePerformanceProperties['title']->isRequired());
        self::assertTrue($favoritePerformanceProperties['role']->isRequired());
        self::assertTrue($favoritePerformanceProperties['year']->isRequired());
        self::assertFalse($favoritePerformanceProperties['tags']->isRequired());
        self::assertSame(['string'], $this->collectTypeNames($favoritePerformanceProperties['title']->getType()));
        self::assertSame(['string'], $this->collectTypeNames($favoritePerformanceProperties['role']->getType()));
        self::assertSame(['int'], $this->collectTypeNames($favoritePerformanceProperties['year']->getType()));
    }

    public function testEnrichWithAdvancedDocTypes(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $birthYear = $this->requireType($this->requireProperty($definition, 'birthYear')->getType(), 'Expected birthYear to have a type.');
        $negative = $this->requireType($this->requireProperty($definition, 'negative')->getType(), 'Expected negative to have a type.');
        $range = $this->requireType($this->requireProperty($definition, 'range')->getType(), 'Expected range to have a type.');
        $nonEmpty = $this->requireType($this->requireProperty($definition, 'nonEmpty')->getType(), 'Expected nonEmpty to have a type.');
        $tags = $this->requireType($this->requireProperty($definition, 'tags')->getType(), 'Expected tags to have a type.');

        $birthYearDecorated = $this->findFirstNonNullDecorated($birthYear);
        $negativeDecorated = $this->findFirstNonNullDecorated($negative);
        $rangeDecorated = $this->findFirstNonNullDecorated($range);
        $nonEmptyDecorated = $this->findFirstNonNullDecorated($nonEmpty);
        $tagsDecorated = $this->findFirstNonNullDecorated($tags);

        self::assertNotNull($birthYearDecorated);
        self::assertNotNull($negativeDecorated);
        self::assertNotNull($rangeDecorated);
        self::assertNotNull($nonEmptyDecorated);
        self::assertNotNull($tagsDecorated);

        self::assertSame(1, $birthYearDecorated->constraints->minimum);
        self::assertSame(-1, $negativeDecorated->constraints->maximum);

        // Assert IntegerRange
        self::assertSame(1, $rangeDecorated->constraints->minimum);
        self::assertSame(100, $rangeDecorated->constraints->maximum);

        // Assert NonEmptyString
        self::assertSame(1, $nonEmptyDecorated->constraints->minLength);

        // Assert NonEmptyArray
        self::assertSame(1, $tagsDecorated->constraints->minItems);
    }

    public function testEnrichResolvesAdditionalPhpDocTypes(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        self::assertSame(['string', 'null'], $this->collectTypeNames($this->requireProperty($definition, 'nullableDocType')->getType()));
        self::assertSame(['string', 'int'], $this->collectTypeNames($this->requireProperty($definition, 'arrayKey')->getType()));
        self::assertSame(['string', 'int', 'float', 'bool'], $this->collectTypeNames($this->requireProperty($definition, 'scalarValue')->getType()));

        $numericText = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'numericText')->getType());
        $alwaysTrue = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'alwaysTrue')->getType());
        $literalStatus = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'literalStatus')->getType());

        self::assertNotNull($numericText);
        self::assertNotNull($alwaysTrue);
        self::assertNotNull($literalStatus);

        self::assertSame('^-?(?:\d+|\d*\.\d+)$', $numericText->constraints->pattern);
        self::assertSame([true], $alwaysTrue->constraints->enum);
        self::assertSame(['draft'], $literalStatus->constraints->enum);
    }
}
