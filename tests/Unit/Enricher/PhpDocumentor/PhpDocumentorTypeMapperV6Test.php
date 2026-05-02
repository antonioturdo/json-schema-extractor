<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Enricher\PhpDocumentor;

use phpDocumentor\Reflection\Type as PhpDocumentorType;
use phpDocumentor\Reflection\TypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor\AbstractPhpDocumentorTypeMapper;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentor\PhpDocumentorTypeMapperV6;
use Zeusi\JsonSchemaExtractor\Model\Type\ArrayType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\InlineObjectType;
use Zeusi\JsonSchemaExtractor\Model\Type\MapType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\UnknownType;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\BasicObject;
use Zeusi\JsonSchemaExtractor\Tests\Support\TypeTestHelperTrait;

#[CoversClass(AbstractPhpDocumentorTypeMapper::class)]
#[CoversClass(PhpDocumentorTypeMapperV6::class)]
final class PhpDocumentorTypeMapperV6Test extends TestCase
{
    use TypeTestHelperTrait;

    private PhpDocumentorTypeMapperV6 $mapper;
    private TypeResolver $typeResolver;

    protected function setUp(): void
    {
        if (!class_exists('phpDocumentor\\Reflection\\PseudoTypes\\Generic')) {
            self::markTestSkipped('phpDocumentor/type-resolver v2 is required for the v6 mapper test.');
        }

        $this->mapper = new PhpDocumentorTypeMapperV6();
        $this->typeResolver = new TypeResolver();
    }

    public function testParseMapsSupportedPhpDocumentorV6Types(): void
    {
        self::assertSame(['string', 'int'], $this->collectTypeNames($this->parse('string|int')));
        self::assertSame(['string', 'int'], $this->collectTypeNames($this->parse('array-key')));
        self::assertSame(['string', 'int', 'float', 'bool'], $this->collectTypeNames($this->parse('scalar')));

        self::assertInstanceOf(UnknownType::class, $this->parse('callable'));

        $shape = $this->parse('array{required:int, optional?:string}');
        self::assertInstanceOf(InlineObjectType::class, $shape);

        $properties = $shape->shape->getProperties();
        self::assertTrue($properties['required']->isRequired());
        self::assertFalse($properties['optional']->isRequired());
        self::assertSame(['int'], $this->collectTypeNames($properties['required']->getType()));
        self::assertSame(['string'], $this->collectTypeNames($properties['optional']->getType()));

        $list = $this->parse('list<string>');
        self::assertInstanceOf(ArrayType::class, $list);
        $this->assertBuiltin($list->type, 'string');

        $map = $this->parse('array<string, int>');
        self::assertInstanceOf(MapType::class, $map);
        $this->assertBuiltin($map->type, 'int');

        $literal = $this->parse("'draft'");
        self::assertInstanceOf(DecoratedType::class, $literal);
        self::assertSame(['draft'], $literal->constraints->enum);

        $positive = $this->parse('positive-int');
        self::assertInstanceOf(DecoratedType::class, $positive);
        self::assertSame(1, $positive->constraints->minimum);

        $numericString = $this->parse('numeric-string');
        self::assertInstanceOf(DecoratedType::class, $numericString);
        self::assertSame('^-?(?:\d+|\d*\.\d+)$', $numericString->constraints->pattern);

        $genericMap = $this->parseGeneric('\\array', [
            $this->typeResolver->resolve('string'),
            $this->typeResolver->resolve('int'),
        ]);
        self::assertInstanceOf(MapType::class, $genericMap);
        $this->assertBuiltin($genericMap->type, 'int');

        $genericList = $this->parseGeneric('\\list', [$this->typeResolver->resolve('string')]);
        self::assertInstanceOf(ArrayType::class, $genericList);
        $this->assertBuiltin($genericList->type, 'string');

        $genericObject = $this->parseGeneric('\\' . BasicObject::class, [$this->typeResolver->resolve('string')]);
        self::assertInstanceOf(ClassLikeType::class, $genericObject);
        self::assertSame(BasicObject::class, $genericObject->name);
    }

    private function parse(string $type): Type
    {
        return $this->mapper->parse(
            $this->typeResolver->resolve($type),
            new \ReflectionClass(BasicObject::class)
        );
    }

    /**
     * @param list<PhpDocumentorType> $types
     */
    private function parseGeneric(string $fqsen, array $types): Type
    {
        $fqsenClass = 'phpDocumentor\\Reflection\\Fqsen';
        $genericClass = 'phpDocumentor\\Reflection\\PseudoTypes\\Generic';

        self::assertTrue(class_exists($fqsenClass));
        self::assertTrue(class_exists($genericClass));

        $generic = new $genericClass(new $fqsenClass($fqsen), $types);

        return $this->mapper->parse(
            $generic,
            new \ReflectionClass(BasicObject::class)
        );
    }
}
