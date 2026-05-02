<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

class ReflectionObject extends ReflectionParentObject
{
    public static string $staticProperty = 'ignored';

    public string $regularDefault = 'default';

    public ?string $nullableString = null;

    public string|int|null $union = null;

    public array $arrayDefault = ['enabled' => true, 'values' => [1, 2, null]];

    public array $nonJsonArrayDefault = [STDIN];

    public StatusEnum $status = StatusEnum::Active;

    public self $selfReference;

    public parent $parentReference;

    public \Stringable $interfaceReference;

    public \Stringable&\Countable $intersection;

    public function __construct(
        public bool $promotedDefault = true,
    ) {}
}
