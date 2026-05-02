<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\JsonEncodeContext;
use Zeusi\JsonSchemaExtractor\Context\ProcessingStackContext;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;

#[CoversClass(ExtractionContext::class)]
#[CoversClass(JsonEncodeContext::class)]
#[CoversClass(ProcessingStackContext::class)]
#[CoversClass(SymfonySerializerContext::class)]
class ExtractionContextTest extends TestCase
{
    public function testWithStoresAndRetrievesCapabilitiesByClassName(): void
    {
        $serializerContext = new SymfonySerializerContext(context: ['groups' => ['read']]);
        $context = (new ExtractionContext())
            ->with($serializerContext);

        $capability = $context->find(SymfonySerializerContext::class);

        self::assertSame($serializerContext, $capability);
    }

    public function testFindReturnsNullWhenCapabilityIsMissing(): void
    {
        $context = new ExtractionContext();

        self::assertNull($context->find(SymfonySerializerContext::class));
    }

    public function testConstructorStoresCapabilitiesAndWithKeepsOriginalContextImmutable(): void
    {
        $serializerContext = new SymfonySerializerContext(context: ['groups' => ['read']]);
        $jsonContext = new JsonEncodeContext(flags: \JSON_FORCE_OBJECT);

        $context = new ExtractionContext([$serializerContext]);
        $extendedContext = $context->with($jsonContext);

        self::assertSame($serializerContext, $context->find(SymfonySerializerContext::class));
        self::assertNull($context->find(JsonEncodeContext::class));
        self::assertSame($serializerContext, $extendedContext->find(SymfonySerializerContext::class));
        self::assertSame($jsonContext, $extendedContext->find(JsonEncodeContext::class));
    }

    public function testWithStoresCapabilitiesByImplementedInterface(): void
    {
        $capability = new TestExtractionCapabilityImplementation();

        $context = (new ExtractionContext())->with($capability);

        self::assertSame($capability, $context->find(TestExtractionCapability::class));
        self::assertSame($capability, $context->find(TestExtractionCapabilityImplementation::class));
    }

    public function testProcessingStackDetectsAndPushesClassesImmutably(): void
    {
        $stack = new ProcessingStackContext();
        $pushedStack = $stack->pushed(\DateTimeImmutable::class);

        self::assertFalse($stack->has(\DateTimeImmutable::class));
        self::assertSame([], $stack->classes);
        self::assertTrue($pushedStack->has(\DateTimeImmutable::class));
        self::assertSame([\DateTimeImmutable::class], $pushedStack->classes);
    }
}

interface TestExtractionCapability {}

final class TestExtractionCapabilityImplementation implements TestExtractionCapability {}
