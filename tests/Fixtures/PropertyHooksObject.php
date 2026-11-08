<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

/**
 * Requires PHP 8.4: only load it from tests marked `#[RequiresPhp('>=8.4')]`.
 */
class PropertyHooksObject
{
    public string $firstName = 'Ada';

    public string $lastName = 'Lovelace';

    public string $fullName {
        get => $this->firstName . ' ' . $this->lastName;
    }

    public string $nickname {
        set(string $nickname) {
            $this->firstName = $nickname;
        }
    }
}
