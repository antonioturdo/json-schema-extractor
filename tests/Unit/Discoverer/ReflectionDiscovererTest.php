<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Discoverer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Model\Php\PropertyVisibility;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\PropertyHooksObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\ReflectionObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\ReflectionParentObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\StatusEnum;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\UserAccount;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(ReflectionDiscoverer::class)]
class ReflectionDiscovererTest extends TestCase
{
    use TypeTestHelperTrait;

    private ReflectionDiscoverer $discoverer;

    protected function setUp(): void
    {
        $this->discoverer = new ReflectionDiscoverer();
    }

    /**
     * Virtual properties are discovered, with their readability recorded.
     *
     * A virtual property with only a `set` hook holds no value, so it is not readable;
     * one with a `get` hook is. Deciding what to serialize is left to the strategy.
     */
    #[RequiresPhp('>=8.4')]
    public function testDiscoverRecordsWhetherVirtualPropertiesAreReadable(): void
    {
        $definition = $this->discoverer->discover(PropertyHooksObject::class);

        self::assertTrue($this->requirePropertyDefinition($definition, 'firstName')->isReadable());
        self::assertTrue($this->requirePropertyDefinition($definition, 'fullName')->isReadable());
        self::assertFalse($this->requirePropertyDefinition($definition, 'nickname')->isReadable());
    }

    public function testDiscoverRecordsReadVisibility(): void
    {
        $definition = $this->discoverer->discover(UserAccount::class);

        self::assertSame(PropertyVisibility::Public, $this->requirePropertyDefinition($definition, 'username')->getReadVisibility());
        self::assertSame(PropertyVisibility::Protected, $this->requirePropertyDefinition($definition, 'role')->getReadVisibility());
        self::assertSame(PropertyVisibility::Private, $this->requirePropertyDefinition($definition, 'passwordHash')->getReadVisibility());
    }

    public function testDiscoverBuildsClassDefinitionFromNativeReflectionMetadata(): void
    {
        $definition = $this->discoverer->discover(ReflectionObject::class);

        self::assertSame(ReflectionObject::class, $definition->getClassName());
        self::assertArrayNotHasKey('staticProperty', $definition->getProperties());

        self::assertSame(['string'], $this->collectTypeNames($this->requireProperty($definition, 'regularDefault')->getType()));
        self::assertSame('default', $this->findFirstDecorated($this->requireProperty($definition, 'regularDefault')->getType())?->annotations?->default);

        self::assertSame(['string', 'null'], $this->collectTypeNames($this->requireProperty($definition, 'nullableString')->getType()));
        self::assertNull($this->findFirstDecorated($this->requireProperty($definition, 'nullableString')->getType())?->annotations?->default);

        self::assertSame(['string', 'int', 'null'], $this->collectTypeNames($this->requireProperty($definition, 'union')->getType()));
        self::assertNull($this->findFirstDecorated($this->requireProperty($definition, 'union')->getType())?->annotations?->default);

        self::assertSame(['array'], $this->collectTypeNames($this->requireProperty($definition, 'arrayDefault')->getType()));
        self::assertSame(['enabled' => true, 'values' => [1, 2, null]], $this->findFirstDecorated($this->requireProperty($definition, 'arrayDefault')->getType())?->annotations?->default);

        self::assertSame(['array'], $this->collectTypeNames($this->requireProperty($definition, 'nonJsonArrayDefault')->getType()));
        self::assertNull($this->findFirstDecorated($this->requireProperty($definition, 'nonJsonArrayDefault')->getType()));

        self::assertSame([StatusEnum::class], $this->collectTypeNames($this->requireProperty($definition, 'status')->getType()));
        self::assertSame('active', $this->findFirstDecorated($this->requireProperty($definition, 'status')->getType())?->annotations?->default);

        self::assertSame([ReflectionObject::class], $this->collectTypeNames($this->requireProperty($definition, 'selfReference')->getType()));
        self::assertSame([ReflectionParentObject::class], $this->collectTypeNames($this->requireProperty($definition, 'parentReference')->getType()));
        self::assertSame([\Stringable::class], $this->collectTypeNames($this->requireProperty($definition, 'interfaceReference')->getType()));

        $intersection = $this->requireProperty($definition, 'intersection')->getType();
        self::assertInstanceOf(IntersectionType::class, $intersection);
        self::assertSame([\Stringable::class], $this->collectTypeNames($intersection->types[0]));
        self::assertSame([\Countable::class], $this->collectTypeNames($intersection->types[1]));

        self::assertSame(['bool'], $this->collectTypeNames($this->requireProperty($definition, 'promotedDefault')->getType()));
        self::assertTrue($this->findFirstDecorated($this->requireProperty($definition, 'promotedDefault')->getType())?->annotations?->default);

        // Only methods readable like a value: no static, magic, void, or argument-requiring ones.
        self::assertEqualsCanonicalizing(
            ['payload', 'label', 'jsonSerialize', 'inheritedMethod'],
            array_keys($definition->getMethods())
        );
        self::assertSame(['array'], $this->collectTypeNames($definition->getMethod('jsonSerialize')?->getReturnType()));
        self::assertSame(['string'], $this->collectTypeNames($definition->getMethod('label')?->getReturnType()));
    }

    /**
     * @param class-string $className
     */
    #[DataProvider('provideTitleDiscovery')]
    public function testDiscoverSetsTitleAccordingToConfiguration(string $className, ?bool $setTitleFromClassName, ?string $expectedTitle): void
    {
        $discoverer = $setTitleFromClassName === null
            ? new ReflectionDiscoverer()
            : new ReflectionDiscoverer(setTitleFromClassName: $setTitleFromClassName);
        $definition = $discoverer->discover($className);

        self::assertSame($expectedTitle, $definition->getTitle());
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
