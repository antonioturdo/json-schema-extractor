<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;

class CollectionValidatedObject
{
    #[Assert\Collection(
        fields: [
            'email' => new Assert\Required([new Assert\NotBlank(), new Assert\Email()]),
            'age' => new Assert\Optional([new Assert\Range(min: 18, max: 99)]),
        ],
        allowExtraFields: false,
        allowMissingFields: false
    )]
    public array $payload = [];

    #[Assert\Collection(
        fields: [
            'email' => new Assert\Required([new Assert\Email()]),
        ],
        allowExtraFields: true,
        allowMissingFields: true
    )]
    public array $payloadLoose = [];

    /**
     * @var array{email: string, age: int} $simpleCollection
     */
    #[Assert\Collection(
        fields: [
            'email' => [new Assert\NotBlank(), new Assert\Email()],
            'age' => [new Assert\Range(min: 18, max: 99)],
        ],
    )]
    public array $simpleCollection = [];
}
