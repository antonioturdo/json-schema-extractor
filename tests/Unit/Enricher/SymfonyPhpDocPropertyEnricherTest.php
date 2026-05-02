<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Enricher;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\SymfonyPhpDocPropertyEnricher;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\PhpDocObject;
use Zeusi\JsonSchemaGenerator\Tests\Support\TypeExprTestHelperTrait;

#[CoversClass(SymfonyPhpDocPropertyEnricher::class)]
class SymfonyPhpDocPropertyEnricherTest extends TestCase
{
    use TypeExprTestHelperTrait;

    /**
     * @param non-empty-string $propertyName
     */
    #[DataProvider('providePropertiesResolvedFromNonPropertyDocBlocks')]
    public function testEnrichResolvesTypesFromAccessorsAndPromotedConstructorParams(string $propertyName): void
    {
        $discoverer = new ReflectionPropertyDiscoverer();
        $enricher = new SymfonyPhpDocPropertyEnricher();

        $definition = $discoverer->discover(PhpDocObject::class);
        $enricher->enrich($definition, new GenerationContext());

        self::assertArrayHasKey($propertyName, $definition->properties);

        $property = $definition->properties[$propertyName];
        $expr = $this->unwrapDecorated($this->requireTypeExpr($property->getTypeExpr(), \sprintf('Expected %s to have a typeExpr.', $propertyName)));
        self::assertInstanceOf(ArrayType::class, $expr);

        $item = $this->unwrapDecorated($expr->type);
        self::assertInstanceOf(BuiltinType::class, $item);
        self::assertSame('string', $item->name);
    }

    /**
     * @return array<string, array{propertyName: non-empty-string}>
     */
    public static function providePropertiesResolvedFromNonPropertyDocBlocks(): array
    {
        return [
            'Promoted property from constructor @param' => [
                'propertyName' => 'promotedList',
            ],
            'Property from getter @return' => [
                'propertyName' => 'getterList',
            ],
        ];
    }
}
