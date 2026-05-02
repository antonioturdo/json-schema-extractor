<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\Runtime\EnrichmentRuntime;
use Zeusi\JsonSchemaExtractor\Enricher\SymfonyValidationEnricher;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\Format;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CollectionValidatedObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\ValidatedObject;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(SymfonyValidationEnricher::class)]
class SymfonyValidationEnricherTest extends TestCase
{
    use TypeTestHelperTrait;

    private ReflectionDiscoverer $discoverer;
    private SymfonyValidationEnricher $enricher;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionDiscoverer();
        $metadataFactory = new LazyLoadingMetadataFactory(new AttributeLoader());
        $this->enricher = new SymfonyValidationEnricher($metadataFactory);
    }

    public function testEnrichAddsValidationConstraints(): void
    {
        $definition = $this->discoverer->discover(ValidatedObject::class);

        $this->enrichPhpDocTypes($definition);
        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $emailExpr = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'email')->getType());
        $ageExpr = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'age')->getType());
        $usernameExpr = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'username')->getType());
        $tagsExpr = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'tags')->getType());
        $internalIdExpr = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'internalId')->getType());
        $serverIpExpr = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'serverIp')->getType());
        $pointsExpr = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'points')->getType());
        $scoreExpr = $this->findFirstNonNullDecorated($this->requireProperty($definition, 'score')->getType());

        self::assertNotNull($emailExpr);
        self::assertNotNull($ageExpr);
        self::assertNotNull($usernameExpr);
        self::assertNotNull($tagsExpr);
        self::assertNotNull($internalIdExpr);
        self::assertNotNull($serverIpExpr);
        self::assertNotNull($pointsExpr);
        self::assertNotNull($scoreExpr);

        // Assert Email format
        self::assertNotNull($emailExpr->annotations);
        self::assertSame(Format::Email->value, $emailExpr->annotations->format);
        self::assertTrue($this->requireProperty($definition, 'email')->isRequired());

        // Assert Range
        self::assertSame(18, $ageExpr->constraints->minimum);
        self::assertSame(99, $ageExpr->constraints->maximum);

        // Assert Length
        self::assertSame(3, $usernameExpr->constraints->minLength);
        self::assertSame(20, $usernameExpr->constraints->maxLength);

        // Assert Count
        self::assertSame(1, $tagsExpr->constraints->minItems);
        self::assertSame(5, $tagsExpr->constraints->maxItems);

        // Assert Uuid
        self::assertNotNull($internalIdExpr->annotations);
        self::assertSame(Format::Uuid->value, $internalIdExpr->annotations->format);

        // Assert Ip
        self::assertNotNull($serverIpExpr->annotations);
        self::assertSame(Format::IPv4->value, $serverIpExpr->annotations->format);

        // Assert Positive (exclusiveMinimum)
        self::assertSame(0, $pointsExpr->constraints->exclusiveMinimum);

        // Assert DivisibleBy (multipleOf)
        self::assertSame(5, $scoreExpr->constraints->multipleOf);

        $emailsExpr = $this->unwrapDecorated($this->requireType($this->requireProperty($definition, 'emails')->getType(), 'Expected emails to have a type.'));
        self::assertInstanceOf(ArrayType::class, $emailsExpr);
        $emailItemExpr = $this->findFirstNonNullDecorated($emailsExpr->type);
        self::assertNotNull($emailItemExpr);
        self::assertNotNull($emailItemExpr->annotations);
        self::assertSame(Format::Email->value, $emailItemExpr->annotations->format);

        $identifiersExpr = $this->unwrapDecorated($this->requireType($this->requireProperty($definition, 'identifiers')->getType(), 'Expected identifiers to have a type.'));
        self::assertInstanceOf(ArrayType::class, $identifiersExpr);
        $identifierItemExpr = $this->findFirstNonNullDecorated($identifiersExpr->type);
        self::assertNotNull($identifierItemExpr);
        self::assertNotNull($identifierItemExpr->annotations);
        self::assertSame(Format::Uuid->value, $identifierItemExpr->annotations->format);
    }

    public function testEnrichMapsCollectionConstraintToObjectShape(): void
    {
        $definition = $this->discoverer->discover(CollectionValidatedObject::class);

        $this->enricher->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());

        $payload = $this->requireProperty($definition, 'payload');
        $shape = $this->assertInlineObject($this->requireType($payload->getType(), 'Expected payload to have a type.'));
        self::assertFalse($shape->getAdditionalProperties());

        $properties = $shape->getProperties();
        self::assertArrayHasKey('email', $properties);
        self::assertTrue($properties['email']->isRequired());
        $emailDecorated = $this->findFirstNonNullDecorated($properties['email']->getType());
        self::assertNotNull($emailDecorated);
        self::assertNotNull($emailDecorated->annotations);
        self::assertSame(Format::Email->value, $emailDecorated->annotations->format);

        self::assertArrayHasKey('age', $properties);
        self::assertFalse($properties['age']->isRequired());

        $simpleCollection = $this->requireProperty($definition, 'simpleCollection');
        $simpleCollectionShape = $this->assertInlineObject($this->requireType($simpleCollection->getType(), 'Expected simpleCollection to have a type.'));
        self::assertFalse($simpleCollectionShape->getAdditionalProperties());

        $simpleCollectionProperties = $simpleCollectionShape->getProperties();
        self::assertArrayHasKey('email', $simpleCollectionProperties);
        self::assertTrue($simpleCollectionProperties['email']->isRequired());
        $emailDecorated2 = $this->findFirstNonNullDecorated($simpleCollectionProperties['email']->getType());
        self::assertNotNull($emailDecorated2);
        self::assertNotNull($emailDecorated2->annotations);
        self::assertSame(Format::Email->value, $emailDecorated2->annotations->format);

        self::assertArrayHasKey('age', $simpleCollectionProperties);
        self::assertTrue($simpleCollectionProperties['age']->isRequired());

        $payloadLoose = $this->requireProperty($definition, 'payloadLoose');
        $looseShape = $this->assertInlineObject($this->requireType($payloadLoose->getType(), 'Expected payloadLoose to have a type.'));
        self::assertTrue($looseShape->getAdditionalProperties());
        self::assertFalse($this->requireProperty($looseShape, 'email')->isRequired());
    }

    private function enrichPhpDocTypes(ClassDefinition $definition): void
    {
        (new PhpDocumentorEnricher())->enrich($definition, new ExtractionContext(), new EnrichmentRuntime());
    }
}
