<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Fixtures;

class UserAccount
{
    public function __construct(
        public string $username,
        protected string $role,
        private string $passwordHash,
    ) {}

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }
}
