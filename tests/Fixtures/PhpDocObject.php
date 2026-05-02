<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

/**
 * Short summary for PhpDocObject.
 *
 * This is the long description of the PHPDoc object
 * that spans multiple lines.
 */
class PhpDocObject
{
    /**
     * @param list<string> $promotedList
     */
    public function __construct(
        public array $promotedList,
        /** @var list<string> */
        public array $promotedVarList,
    ) {}

    /** @deprecated Use named_field instead */
    public int $id;

    /**
     * @example "Park"
     * @example "Eun-bin"
     */
    public string $name;

    /**
     * Title of the union field.
     *
     * Description of the union field.
     */
    public string|int|null $union = null;

    /** @var BasicObject[] */
    public array $objects = [];

    /**
     * @var array<StatusEnum|string>
     * Mixed array of enums and strings.
     */
    public array $mixedTags = [];

    /** @var array<string, string> */
    public array $headers = [];

    /**
     * @var array{id: int, name: string, tags?: string[]}
     */
    public array $settings;

    /**
     * @var array<array{url: string, active: bool}>
     */
    public array $endpoints;

    /** @var positive-int */
    public int $positive;

    /** @var negative-int */
    public int $negative;

    /** @var int<1, 100> */
    public int $range;

    /** @var non-empty-string */
    public string $nonEmpty;

    /** @var lowercase-string */
    public string $lowercase;

    /** @var non-empty-lowercase-string */
    public string $nonEmptyLowercase;

    /**
     * @var string[]
     * @var non-empty-array
     */
    public array $tags;

    /** @var string|int|null */
    public $varUnion;

    /** @var list<string> */
    public array $list;

    /** @var iterable<int> */
    public $iterable;

    /** @var ?string */
    public $nullableDocType;

    /** @var array-key */
    public $arrayKey;

    /** @var scalar */
    public $scalarValue;

    /** @var numeric-string */
    public $numericText;

    /** @var true */
    public $alwaysTrue;

    /** @var "draft" */
    public $literalStatus;

    /** @var 123 */
    public $literalCode;

    /** @var object{id: int, name?: string} */
    public $objectShape;

    /** @var callable(int): string */
    public $callableSignature;

    /** @var class-string */
    public $classNameString;

    /** @var literal-string */
    public $literalText;

    /** @var key-of<StatusEnum> */
    public $enumKey;

    /** @var value-of<StatusEnum> */
    public $enumValue;

    /** @var int-mask<1, 2, 4> */
    public $permissionsMask;

    private array $getterList = [];

    /**
     * @return list<string>
     */
    public function getGetterList(): array
    {
        return $this->getterList;
    }
}
