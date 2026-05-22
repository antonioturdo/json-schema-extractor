<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

final class CustomNormalizedObject
{
    public function __construct(
        public readonly CustomNormalizedMoney $amount,
        public readonly CustomNormalizedOwner $owner,
        public readonly string $label,
    ) {}
}
