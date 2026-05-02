<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;

class ValidatedObject
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email;

    #[Assert\Range(min: 18, max: 99)]
    public int $age;

    #[Assert\Length(min: 3, max: 20)]
    public ?string $username = null;

    #[Assert\Count(min: 1, max: 5)]
    /** @var string[] */
    public array $tags = [];

    #[Assert\Regex(pattern: '/^[A-Z0-9]+$/', htmlPattern: '^[A-Z0-9]+$')]
    public string $sku;

    #[Assert\Uuid]
    public string $internalId;

    #[Assert\Ip(version: '4')]
    public string $serverIp;

    #[Assert\Positive]
    public int $points;

    #[Assert\DivisibleBy(5)]
    public int $score;

    #[Assert\All([new Assert\Email()])]
    /** @var string[] */
    public array $emails = [];
}
