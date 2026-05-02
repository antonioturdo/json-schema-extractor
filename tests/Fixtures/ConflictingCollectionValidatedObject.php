<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;

class ConflictingCollectionValidatedObject
{
    /**
     * @var array{email: int} $simpleCollection
     */
    #[Assert\Collection(
        fields: [
            'email' => [new Assert\NotBlank(), new Assert\Email()],
        ],
    )]
    public array $simpleCollection = [];
}
