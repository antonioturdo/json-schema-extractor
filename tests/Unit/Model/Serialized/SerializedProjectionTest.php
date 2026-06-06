<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Model\Serialized;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedProjection;
use Zeusi\JsonSchemaExtractor\Model\Serialized\ViewId;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;

#[CoversClass(SerializedProjection::class)]
#[CoversClass(ViewId::class)]
final class SerializedProjectionTest extends TestCase
{
    public function testRootAndRootPayloadAndGet(): void
    {
        $rootId = new ViewId('Root');
        $payload = new SerializedPayloadDefinition(new BuiltinType('string'));

        $projection = new SerializedProjection($rootId, [$rootId->key() => $payload]);

        self::assertSame($rootId, $projection->root());
        self::assertSame($payload, $projection->rootPayload());
        self::assertSame($payload, $projection->get($rootId));
    }

    public function testHasReflectsRegistration(): void
    {
        $rootId = new ViewId('Root');
        $projection = new SerializedProjection($rootId, [
            $rootId->key() => new SerializedPayloadDefinition(new BuiltinType('string')),
        ]);

        self::assertTrue($projection->has($rootId));
        self::assertFalse($projection->has(new ViewId('Missing')));
    }

    public function testViewKeyDisambiguatesEntriesForTheSameClass(): void
    {
        $canonical = new ViewId('Foo');
        $narrowed = new ViewId('Foo', 'abc123');

        self::assertNotSame($canonical->key(), $narrowed->key());
    }

    public function testGetThrowsForUnknownView(): void
    {
        $projection = new SerializedProjection(new ViewId('Root'), []);

        $this->expectException(\LogicException::class);

        $projection->get(new ViewId('Missing'));
    }
}
