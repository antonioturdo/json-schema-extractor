<?php

namespace Zeusi\JsonSchemaExtractor\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Zeusi\JsonSchemaExtractor\Enricher\SymfonyValidationEnricher;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;

final class RegisterOptionalIntegrationsPass implements CompilerPassInterface
{
    private const SERVICE_SERIALIZATION_SYMFONY_SERIALIZER = 'json_schema_extractor.serialization.symfony_serializer';
    private const SERVICE_ENRICHER_SYMFONY_VALIDATOR = 'json_schema_extractor.enricher.symfony_validator';
    private const SERVICE_SYMFONY_SERIALIZER_METADATA_FACTORY = 'serializer.mapping.class_metadata_factory';
    private const SERVICE_SYMFONY_SERIALIZER_NAME_CONVERTER = 'serializer.name_converter';
    private const SERVICE_SYMFONY_VALIDATOR_METADATA_FACTORY = 'validator.mapping.class_metadata_factory';

    public function process(ContainerBuilder $container): void
    {
        $this->registerSymfonySerializerStrategy($container);
        $this->registerSymfonyValidationEnricher($container);
    }

    private function registerSymfonySerializerStrategy(ContainerBuilder $container): void
    {
        if (!interface_exists(ClassMetadataFactoryInterface::class) || !$container->has(self::SERVICE_SYMFONY_SERIALIZER_METADATA_FACTORY)) {
            return;
        }

        $definition = (new Definition(SymfonySerializerStrategy::class))
            ->setArgument('$classMetadataFactory', new Reference(self::SERVICE_SYMFONY_SERIALIZER_METADATA_FACTORY))
            ->setPublic(true);

        if (interface_exists(NameConverterInterface::class) && $container->has(self::SERVICE_SYMFONY_SERIALIZER_NAME_CONVERTER)) {
            $definition->setArgument('$nameConverter', new Reference(self::SERVICE_SYMFONY_SERIALIZER_NAME_CONVERTER));
        }

        $container->setDefinition(self::SERVICE_SERIALIZATION_SYMFONY_SERIALIZER, $definition);
        $container->setAlias(SymfonySerializerStrategy::class, new Alias(self::SERVICE_SERIALIZATION_SYMFONY_SERIALIZER, true));
    }

    private function registerSymfonyValidationEnricher(ContainerBuilder $container): void
    {
        if (!interface_exists(MetadataFactoryInterface::class) || !$container->has(self::SERVICE_SYMFONY_VALIDATOR_METADATA_FACTORY)) {
            return;
        }

        $container->setDefinition(
            self::SERVICE_ENRICHER_SYMFONY_VALIDATOR,
            (new Definition(SymfonyValidationEnricher::class))
                ->setArgument('$metadataFactory', new Reference(self::SERVICE_SYMFONY_VALIDATOR_METADATA_FACTORY))
                ->setPublic(true)
        );
    }
}
