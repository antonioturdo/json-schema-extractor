<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\SymfonyValidationEnricher;
use Zeusi\JsonSchemaGenerator\JsonSchema\Format;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\CollectionValidatedObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\ValidatedObject;
use Zeusi\JsonSchemaGenerator\Tests\Support\TypeExprTestHelperTrait;

#[CoversClass(SymfonyValidationEnricher::class)]
class SymfonyValidationEnricherTest extends TestCase
{
    use TypeExprTestHelperTrait;

    private ReflectionPropertyDiscoverer $discoverer;
    private SymfonyValidationEnricher $enricher;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionPropertyDiscoverer();
        $metadataFactory = new LazyLoadingMetadataFactory(new AttributeLoader());
        $this->enricher = new SymfonyValidationEnricher($metadataFactory);
    }

    public function testEnrichAddsValidationConstraints(): void
    {
        $definition = $this->discoverer->discover(ValidatedObject::class);

        $this->enrichPhpDocTypes($definition);
        $this->enricher->enrich($definition, new GenerationContext());

        $emailExpr = $this->findFirstNonNullDecorated($definition->properties['email']->getTypeExpr());
        $ageExpr = $this->findFirstNonNullDecorated($definition->properties['age']->getTypeExpr());
        $usernameExpr = $this->findFirstNonNullDecorated($definition->properties['username']->getTypeExpr());
        $tagsExpr = $this->findFirstNonNullDecorated($definition->properties['tags']->getTypeExpr());
        $internalIdExpr = $this->findFirstNonNullDecorated($definition->properties['internalId']->getTypeExpr());
        $serverIpExpr = $this->findFirstNonNullDecorated($definition->properties['serverIp']->getTypeExpr());
        $pointsExpr = $this->findFirstNonNullDecorated($definition->properties['points']->getTypeExpr());
        $scoreExpr = $this->findFirstNonNullDecorated($definition->properties['score']->getTypeExpr());

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
        self::assertTrue($definition->properties['email']->isRequired());

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

        $emailsExpr = $this->unwrapDecorated($this->requireTypeExpr($definition->properties['emails']->getTypeExpr(), 'Expected emails to have a typeExpr.'));
        self::assertInstanceOf(ArrayType::class, $emailsExpr);
        $emailItemExpr = $this->findFirstNonNullDecorated($emailsExpr->type);
        self::assertNotNull($emailItemExpr);
        self::assertNotNull($emailItemExpr->annotations);
        self::assertSame(Format::Email->value, $emailItemExpr->annotations->format);
    }

    public function testEnrichMapsCollectionConstraintToObjectShape(): void
    {
        $definition = $this->discoverer->discover(CollectionValidatedObject::class);

        $this->enricher->enrich($definition, new GenerationContext());

        $payload = $definition->properties['payload'];
        $shape = $this->assertInlineObject($this->requireTypeExpr($payload->getTypeExpr(), 'Expected payload to have a typeExpr.'));
        self::assertFalse($shape->additionalProperties);

        $properties = $shape->getProperties();
        self::assertArrayHasKey('email', $properties);
        self::assertTrue($properties['email']->isRequired());
        $emailDecorated = $this->findFirstNonNullDecorated($properties['email']->getTypeExpr());
        self::assertNotNull($emailDecorated);
        self::assertNotNull($emailDecorated->annotations);
        self::assertSame(Format::Email->value, $emailDecorated->annotations->format);

        self::assertArrayHasKey('age', $properties);
        self::assertFalse($properties['age']->isRequired());

        $payloadLoose = $definition->properties['payloadLoose'];
        $looseShape = $this->assertInlineObject($this->requireTypeExpr($payloadLoose->getTypeExpr(), 'Expected payloadLoose to have a typeExpr.'));
        self::assertTrue($looseShape->additionalProperties);
        self::assertFalse($looseShape->getProperty('email')?->isRequired());
    }

    private function enrichPhpDocTypes(ClassDefinition $definition): void
    {
        (new PhpDocumentorEnricher())->enrich($definition, new GenerationContext());
    }
}
