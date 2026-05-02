<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

class BasicObject
{
    public function __construct(
        #[Groups(['read'])]
        public int $id,
        #[SerializedName('renamed_field')]
        public string $name,
        public ?float $price = null,
        public bool $isActive = true,
        public string|int|null $union = null,
        public StatusEnum $status = StatusEnum::Active,
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}
}
