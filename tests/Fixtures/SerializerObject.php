<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateIntervalNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\UidNormalizer;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

class SerializerObject
{
    /** @var list<\DateTimeImmutable> */
    public array $events = [];

    /** @var array{theme: string} */
    public array $preferences = [];

    public function __construct(
        #[Groups(['read'])]
        public int $id,
        #[SerializedName('renamed_field')]
        public string $name,
        public string|int|null $union,
        public \DateTimeInterface $createdAt,
        #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]
        public \DateTimeInterface $birthDate,
        public \DateTimeZone $timezone,
        public \DateInterval $duration,
        #[Context([DateIntervalNormalizer::FORMAT_KEY => '%d days'])]
        public \DateInterval $customDuration,
        public Uuid $uuid,
        public Ulid $ulid,
        #[Context([UidNormalizer::NORMALIZATION_FORMAT_KEY => UidNormalizer::NORMALIZATION_FORMAT_BASE58])]
        public Uuid $base58Uuid,
        public TranslatableInterface $message,
        public \SplFileInfo $file,
        public ConstraintViolationListInterface $violations,
        public FlattenException $problem,
    ) {}
}
