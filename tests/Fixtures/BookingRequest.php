<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pairs every mapped constraint with a variant that must stay unmapped, because its
 * JSON Schema counterpart would reject values Symfony Validator accepts.
 */
class BookingRequest
{
    #[Assert\Ulid]
    public string $id;

    #[Assert\Date]
    public string $checkIn;

    #[Assert\DateTime]
    public string $requestedAt;

    #[Assert\DateTime(format: 'd/m/Y H:i')]
    public string $legacyTimestamp;

    #[Assert\Time]
    public string $arrivalTime;

    #[Assert\Time(withSeconds: false)]
    public string $departureTime;

    #[Assert\EqualTo('EUR')]
    public string $currency;

    #[Assert\EqualTo('2')]
    public string $apiVersion;

    #[Assert\IdenticalTo('web')]
    public string $channel;

    #[Assert\EqualTo(propertyPath: 'checkIn')]
    public string $confirmedCheckIn;
}
