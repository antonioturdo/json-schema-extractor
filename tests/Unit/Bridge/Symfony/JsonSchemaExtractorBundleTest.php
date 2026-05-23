<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Bridge\Symfony;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Zeusi\JsonSchemaExtractor\Bridge\Symfony\Command\ExtractJsonSchemaCommand;
use Zeusi\JsonSchemaExtractor\Bridge\Symfony\JsonSchemaExtractorBundle;
use Zeusi\JsonSchemaExtractor\Discoverer\DiscovererInterface;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Mapper\JsonSchemaMapperInterface;
use Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapper;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;

#[CoversClass(JsonSchemaExtractorBundle::class)]
final class JsonSchemaExtractorBundleTest extends TestCase
{
    public function testLoadExtensionRegistersBuiltInServices(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new JsonSchemaExtractorBundle();
        $bundle->build($container);
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([[
            'default_extractor' => 'app',
            'extractors' => [
                'app' => [
                    'serialization' => 'json_schema_extractor.serialization.json_encode',
                ],
            ],
        ]], $container);

        $container->register('serializer.mapping.class_metadata_factory', ClassMetadataFactoryInterface::class);
        $container->register('serializer.name_converter', NameConverterInterface::class);
        $container->register('validator.mapping.class_metadata_factory', MetadataFactoryInterface::class);
        $container->compile();

        self::assertTrue($container->hasDefinition('json_schema_extractor.discoverer.reflection'));
        self::assertTrue($container->hasAlias(ReflectionDiscoverer::class));
        self::assertTrue($container->hasAlias(DiscovererInterface::class));

        self::assertTrue($container->hasDefinition('json_schema_extractor.mapper.standard_json_schema_options'));
        self::assertTrue($container->hasDefinition('json_schema_extractor.mapper.standard_json_schema'));
        self::assertTrue($container->hasAlias(StandardJsonSchemaMapper::class));
        self::assertTrue($container->hasAlias(JsonSchemaMapperInterface::class));

        self::assertTrue($container->hasDefinition('json_schema_extractor.serialization.json_encode'));
        self::assertTrue($container->hasAlias(JsonEncodeSerializationStrategy::class));
        self::assertTrue($container->hasDefinition('json_schema_extractor.serialization.symfony_serializer'));
        self::assertTrue($container->hasAlias(SymfonySerializerStrategy::class));

        self::assertTrue($container->hasDefinition('json_schema_extractor.enricher.phpstan'));
        self::assertTrue($container->hasDefinition('json_schema_extractor.enricher.phpdocumentor'));
        self::assertTrue($container->hasDefinition('json_schema_extractor.enricher.symfony_validator'));
    }

    public function testLoadExtensionCanConfigureNamedExtractorWithServiceIds(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new JsonSchemaExtractorBundle();
        $bundle->build($container);
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([[
            'default_extractor' => 'app',
            'extractors' => [
                'app' => [
                    'enrichers' => ['json_schema_extractor.enricher.phpstan'],
                    'serialization' => 'json_schema_extractor.serialization.json_encode',
                ],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('json_schema_extractor.extractor.app'));
        self::assertTrue($container->hasAlias(SchemaExtractor::class));
        self::assertTrue($container->hasDefinition('json_schema_extractor.command.extract'));
        self::assertSame(ExtractJsonSchemaCommand::class, $container->getDefinition('json_schema_extractor.command.extract')->getClass());
    }

    public function testLoadExtensionRejectsUnknownDefaultExtractor(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new JsonSchemaExtractorBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $this->expectException(InvalidConfigurationException::class);

        $extension->load([[
            'default_extractor' => 'missing',
            'extractors' => [
                'app' => [
                    'serialization' => 'json_schema_extractor.serialization.json_encode',
                ],
            ],
        ]], $container);
    }
}
