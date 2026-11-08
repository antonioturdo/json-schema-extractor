<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

class CustomerProfile
{
    public function __construct(
        public string $displayName,
        private string $email,
        private bool $verified,
        private string $internalNotes,
    ) {}

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getInitials(): string
    {
        return strtoupper(substr($this->displayName, 0, 1));
    }
}
