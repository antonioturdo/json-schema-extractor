<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\ClassLikeType;
use Zeusi\JsonSchemaGenerator\Definition\Type\EnumType;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionType;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\PhpDocObject;
use Zeusi\JsonSchemaGenerator\Tests\Support\TypeExprTestHelperTrait;

#[CoversClass(PhpDocumentorEnricher::class)]
class PhpDocumentorEnricherTest extends TestCase
{
    use TypeExprTestHelperTrait;

    private ReflectionPropertyDiscoverer $discoverer;
    private PhpDocumentorEnricher $enricher;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionPropertyDiscoverer();
        $this->enricher = new PhpDocumentorEnricher();
    }

    public function testEnrichExtractsClassMetadata(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);
        $this->enricher->enrich($definition, new GenerationContext());

        self::assertSame('Short summary for PhpDocObject.', $definition->title);
        self::assertNotNull($definition->description);
        self::assertStringContainsString('long description of the PHPDoc object', $definition->description);
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
        self::assertSame('Title of the union field.', $unionDecorated->annotations->title);
        self::assertSame('Description of the union field.', $unionDecorated->annotations->description);
    }

    public function testEnrichResolvesGenericCollections(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        $objectsExpr = $this->unwrapDecorated($this->requireTypeExpr($definition->properties['objects']->getTypeExpr(), 'Expected objects to have a typeExpr.'));
        self::assertInstanceOf(ArrayType::class, $objectsExpr);
        self::assertInstanceOf(ClassLikeType::class, $objectsExpr->type);
        self::assertSame('Zeusi\\JsonSchemaGenerator\\Tests\\Fixtures\\BasicObject', $objectsExpr->type->name);

        $mixedExpr = $this->unwrapDecorated($this->requireTypeExpr($definition->properties['mixedTags']->getTypeExpr(), 'Expected mixedTags to have a typeExpr.'));
        self::assertInstanceOf(ArrayType::class, $mixedExpr);
        $inner = $this->unwrapDecorated($mixedExpr->type);
        self::assertInstanceOf(UnionType::class, $inner);

        $hasString = false;
        $hasEnumBranch = false;
        foreach ($inner->types as $subType) {
            if ($subType instanceof EnumType) {
                self::assertSame('Zeusi\\JsonSchemaGenerator\\Tests\\Fixtures\\StatusEnum', $subType->className);
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

        $settingsShape = $this->assertInlineObject($this->requireTypeExpr($definition->properties['settings']->getTypeExpr(), 'Expected settings to have a typeExpr.'));
        $settingsProperties = $settingsShape->getProperties();

        self::assertTrue($settingsProperties['id']->isRequired());
        self::assertTrue($settingsProperties['name']->isRequired());
        self::assertFalse($settingsProperties['tags']->isRequired());
        self::assertSame(['int'], $this->collectTypeNames($settingsProperties['id']->getTypeExpr()));
        self::assertSame(['string'], $this->collectTypeNames($settingsProperties['name']->getTypeExpr()));
    }

    public function testEnrichWithAdvancedDocTypes(): void
    {
        $definition = $this->discoverer->discover(PhpDocObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        $positive = $this->requireTypeExpr($definition->properties['positive']->getTypeExpr(), 'Expected positive to have a typeExpr.');
        $negative = $this->requireTypeExpr($definition->properties['negative']->getTypeExpr(), 'Expected negative to have a typeExpr.');
        $range = $this->requireTypeExpr($definition->properties['range']->getTypeExpr(), 'Expected range to have a typeExpr.');
        $nonEmpty = $this->requireTypeExpr($definition->properties['nonEmpty']->getTypeExpr(), 'Expected nonEmpty to have a typeExpr.');
        $tags = $this->requireTypeExpr($definition->properties['tags']->getTypeExpr(), 'Expected tags to have a typeExpr.');

        $positiveDecorated = $this->findFirstNonNullDecorated($positive);
        $negativeDecorated = $this->findFirstNonNullDecorated($negative);
        $rangeDecorated = $this->findFirstNonNullDecorated($range);
        $nonEmptyDecorated = $this->findFirstNonNullDecorated($nonEmpty);
        $tagsDecorated = $this->findFirstNonNullDecorated($tags);

        self::assertNotNull($positiveDecorated);
        self::assertNotNull($negativeDecorated);
        self::assertNotNull($rangeDecorated);
        self::assertNotNull($nonEmptyDecorated);
        self::assertNotNull($tagsDecorated);

        self::assertSame(1, $positiveDecorated->constraints->minimum);
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

        $this->enricher->enrich($definition, new GenerationContext());

        self::assertSame(['string', 'null'], $this->collectTypeNames($definition->properties['nullableDocType']->getTypeExpr()));
        self::assertSame(['string', 'int'], $this->collectTypeNames($definition->properties['arrayKey']->getTypeExpr()));
        self::assertSame(['string', 'int', 'float', 'bool'], $this->collectTypeNames($definition->properties['scalarValue']->getTypeExpr()));

        $numericText = $this->findFirstNonNullDecorated($definition->properties['numericText']->getTypeExpr());
        $alwaysTrue = $this->findFirstNonNullDecorated($definition->properties['alwaysTrue']->getTypeExpr());
        $literalStatus = $this->findFirstNonNullDecorated($definition->properties['literalStatus']->getTypeExpr());

        self::assertNotNull($numericText);
        self::assertNotNull($alwaysTrue);
        self::assertNotNull($literalStatus);

        self::assertSame('^-?(?:\d+|\d*\.\d+)$', $numericText->constraints->pattern);
        self::assertSame([true], $alwaysTrue->constraints->enum);
        self::assertSame(['draft'], $literalStatus->constraints->enum);
    }
}
