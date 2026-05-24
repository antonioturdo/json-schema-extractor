<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Zeusi\JsonSchemaExtractor\Bridge\Symfony\DependencyInjection\RegisterOptionalIntegrationsPass;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;

#[CoversClass(RegisterOptionalIntegrationsPass::class)]
final class RegisterOptionalIntegrationsPassTest extends TestCase
{
    public function testProcessRegistersOptionalIntegrationsWhenSymfonyServicesAreAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->register('serializer.mapping.class_metadata_factory', ClassMetadataFactoryInterface::class);
        $container->register('serializer.name_converter', NameConverterInterface::class);
        $container->register('validator.mapping.class_metadata_factory', MetadataFactoryInterface::class);

        (new RegisterOptionalIntegrationsPass())->process($container);

        self::assertTrue($container->hasDefinition('json_schema_extractor.serialization.symfony_serializer'));
        self::assertTrue($container->hasAlias(SymfonySerializerStrategy::class));
        self::assertTrue($container->hasDefinition('json_schema_extractor.enricher.symfony_validator'));
    }

    public function testProcessSkipsOptionalIntegrationsWhenSymfonyServicesAreMissing(): void
    {
        $container = new ContainerBuilder();

        (new RegisterOptionalIntegrationsPass())->process($container);

        self::assertFalse($container->hasDefinition('json_schema_extractor.serialization.symfony_serializer'));
        self::assertFalse($container->hasAlias(SymfonySerializerStrategy::class));
        self::assertFalse($container->hasDefinition('json_schema_extractor.enricher.symfony_validator'));
    }
}
