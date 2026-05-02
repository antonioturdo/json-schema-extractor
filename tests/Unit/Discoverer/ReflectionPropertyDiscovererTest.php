<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Discoverer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaGenerator\Definition\Type\IntersectionType;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\ReflectionObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\ReflectionParentObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\StatusEnum;
use Zeusi\JsonSchemaGenerator\Tests\Support\TypeExprTestHelperTrait;

#[CoversClass(ReflectionPropertyDiscoverer::class)]
class ReflectionPropertyDiscovererTest extends TestCase
{
    use TypeExprTestHelperTrait;

    private ReflectionPropertyDiscoverer $discoverer;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionPropertyDiscoverer();
    }

    public function testDiscoverBuildsClassDefinitionFromNativeReflectionMetadata(): void
    {
        $definition = $this->discoverer->discover(ReflectionObject::class);

        self::assertSame(ReflectionObject::class, $definition->className);
        self::assertArrayNotHasKey('staticProperty', $definition->properties);

        self::assertSame(['string'], $this->collectTypeNames($definition->properties['regularDefault']->getTypeExpr()));
        self::assertSame('default', $this->findFirstDecorated($definition->properties['regularDefault']->getTypeExpr())?->annotations?->default);

        self::assertSame(['string', 'null'], $this->collectTypeNames($definition->properties['nullableString']->getTypeExpr()));
        self::assertNull($this->findFirstDecorated($definition->properties['nullableString']->getTypeExpr())?->annotations?->default);

        self::assertSame(['string', 'int', 'null'], $this->collectTypeNames($definition->properties['union']->getTypeExpr()));
        self::assertNull($this->findFirstDecorated($definition->properties['union']->getTypeExpr())?->annotations?->default);

        self::assertSame(['array'], $this->collectTypeNames($definition->properties['arrayDefault']->getTypeExpr()));
        self::assertSame(['enabled' => true, 'values' => [1, 2, null]], $this->findFirstDecorated($definition->properties['arrayDefault']->getTypeExpr())?->annotations?->default);

        self::assertSame(['array'], $this->collectTypeNames($definition->properties['nonJsonArrayDefault']->getTypeExpr()));
        self::assertNull($this->findFirstDecorated($definition->properties['nonJsonArrayDefault']->getTypeExpr()));

        self::assertSame([StatusEnum::class], $this->collectTypeNames($definition->properties['status']->getTypeExpr()));
        self::assertSame('active', $this->findFirstDecorated($definition->properties['status']->getTypeExpr())?->annotations?->default);

        self::assertSame([ReflectionObject::class], $this->collectTypeNames($definition->properties['selfReference']->getTypeExpr()));
        self::assertSame([ReflectionParentObject::class], $this->collectTypeNames($definition->properties['parentReference']->getTypeExpr()));
        self::assertSame([\Stringable::class], $this->collectTypeNames($definition->properties['interfaceReference']->getTypeExpr()));

        $intersection = $definition->properties['intersection']->getTypeExpr();
        self::assertInstanceOf(IntersectionType::class, $intersection);
        self::assertSame([\Stringable::class], $this->collectTypeNames($intersection->types[0]));
        self::assertSame([\Countable::class], $this->collectTypeNames($intersection->types[1]));

        self::assertSame(['bool'], $this->collectTypeNames($definition->properties['promotedDefault']->getTypeExpr()));
        self::assertTrue($this->findFirstDecorated($definition->properties['promotedDefault']->getTypeExpr())?->annotations?->default);
    }

    /**
     * @param class-string $className
     */
    #[DataProvider('provideTitleDiscovery')]
    public function testDiscoverSetsTitleAccordingToConfiguration(string $className, ?bool $setTitleFromClassName, ?string $expectedTitle): void
    {
        $discoverer = $setTitleFromClassName === null
            ? new ReflectionPropertyDiscoverer()
            : new ReflectionPropertyDiscoverer(setTitleFromClassName: $setTitleFromClassName);
        $definition = $discoverer->discover($className);

        self::assertSame($expectedTitle, $definition->title);
    }

    /**
     * @return array<string, array{className: class-string, setTitleFromClassName: bool|null, expectedTitle: string|null}>
     */
    public static function provideTitleDiscovery(): array
    {
        return [
            'Enabled by default uses short class name' => [
                'className' => ReflectionObject::class,
                'setTitleFromClassName' => null,
                'expectedTitle' => 'ReflectionObject',
            ],
            'Disabled explicitly' => [
                'className' => ReflectionObject::class,
                'setTitleFromClassName' => false,
                'expectedTitle' => null,
            ],
            'Enabled explicitly uses short class name' => [
                'className' => ReflectionObject::class,
                'setTitleFromClassName' => true,
                'expectedTitle' => 'ReflectionObject',
            ],
        ];
    }

}
