<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Bridge\Symfony\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Zeusi\JsonSchemaExtractor\Bridge\Symfony\Command\ExtractJsonSchemaCommand;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\BasicObject;

#[CoversClass(ExtractJsonSchemaCommand::class)]
final class ExtractJsonSchemaCommandTest extends TestCase
{
    public function testExecuteWritesExtractedSchemaAsJson(): void
    {
        $defaultExtractor = $this->createMock(SchemaExtractor::class);
        $customExtractor = $this->createMock(SchemaExtractor::class);
        $defaultExtractor
            ->expects(self::never())
            ->method('extract');
        $customExtractor
            ->expects(self::once())
            ->method('extract')
            ->with(BasicObject::class)
            ->willReturn([
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
            ]);

        $tester = new CommandTester(new ExtractJsonSchemaCommand(new ServiceLocator([
            'default' => static fn(): SchemaExtractor => $defaultExtractor,
            'custom' => static fn(): SchemaExtractor => $customExtractor,
        ]), 'default'));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'class' => BasicObject::class,
            '--extractor' => 'custom',
            '--compact' => true,
        ]));
        self::assertSame('{"type":"object","properties":{"name":{"type":"string"}}}' . PHP_EOL, $tester->getDisplay());
    }

    public function testExecuteUsesDefaultExtractorWhenNoExtractorOptionIsProvided(): void
    {
        $extractor = $this->createMock(SchemaExtractor::class);
        $extractor
            ->expects(self::once())
            ->method('extract')
            ->with(BasicObject::class)
            ->willReturn([
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ]);

        $tester = new CommandTester(new ExtractJsonSchemaCommand(new ServiceLocator([
            'app' => static fn(): SchemaExtractor => $extractor,
        ]), 'app'));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'class' => BasicObject::class,
            '--compact' => true,
        ]));

        $schema = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('object', $schema['type']);
        self::assertSame('integer', $schema['properties']['id']['type']);
    }
}
