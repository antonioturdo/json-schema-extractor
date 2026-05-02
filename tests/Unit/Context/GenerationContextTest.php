<?php

namespace Zeusi\JsonSchemaGenerator\Tests\Unit\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Context\SymfonySerializerContext;

#[CoversClass(GenerationContext::class)]
#[CoversClass(SymfonySerializerContext::class)]
class GenerationContextTest extends TestCase
{
    public function testWithStoresAndRetrievesCapabilitiesByClassName(): void
    {
        $serializerContext = new SymfonySerializerContext(context: ['groups' => ['read']]);
        $context = (new GenerationContext())
            ->with($serializerContext);

        $capability = $context->find(SymfonySerializerContext::class);

        self::assertSame($serializerContext, $capability);
    }

    public function testFindReturnsNullWhenCapabilityIsMissing(): void
    {
        $context = new GenerationContext();

        self::assertNull($context->find(SymfonySerializerContext::class));
    }
}
