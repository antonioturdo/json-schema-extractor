<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Integration\Bridge\Symfony;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\BasicObject;

#[CoversNothing]
final class SymfonyMiniAppCommandTest extends TestCase
{
    public function testMiniAppCanExecuteExtractCommandFromConsole(): void
    {
        $process = new Process([
            PHP_BINARY,
            'tests/Fixtures/SymfonyApp/bin/console',
            'json-schema-extractor:extract',
            BasicObject::class,
            '--compact',
        ]);
        $process->setWorkingDirectory(\dirname(__DIR__, 4));
        $process->mustRun();

        $schema = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('object', $schema['type']);
        self::assertSame('integer', $schema['properties']['id']['type']);
        self::assertSame('string', $schema['properties']['name']['type']);
    }
}
