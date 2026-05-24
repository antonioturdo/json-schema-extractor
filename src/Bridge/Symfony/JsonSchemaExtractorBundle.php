<?php

namespace Zeusi\JsonSchemaExtractor\Bridge\Symfony;

use phpDocumentor\Reflection\DocBlockFactory;
use PHPStan\PhpDocParser\ParserConfig;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\iterator;

use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Zeusi\JsonSchemaExtractor\Bridge\Symfony\Command\ExtractJsonSchemaCommand;
use Zeusi\JsonSchemaExtractor\Bridge\Symfony\DependencyInjection\RegisterOptionalIntegrationsPass;
use Zeusi\JsonSchemaExtractor\Discoverer\DiscovererInterface;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Mapper\JsonSchemaMapperInterface;
use Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapper;
use Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapperOptions;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;

final class JsonSchemaExtractorBundle extends AbstractBundle
{
    protected string $extensionAlias = 'json_schema_extractor';

    private const SERVICE_EXTRACTOR_PREFIX = 'json_schema_extractor.extractor.';
    private const SERVICE_DISCOVERER_REFLECTION = 'json_schema_extractor.discoverer.reflection';
    private const SERVICE_MAPPER_STANDARD_JSON_SCHEMA = 'json_schema_extractor.mapper.standard_json_schema';
    private const SERVICE_MAPPER_STANDARD_JSON_SCHEMA_OPTIONS = 'json_schema_extractor.mapper.standard_json_schema_options';
    private const SERVICE_SERIALIZATION_JSON_ENCODE = 'json_schema_extractor.serialization.json_encode';
    private const SERVICE_ENRICHER_PHPSTAN = 'json_schema_extractor.enricher.phpstan';
    private const SERVICE_ENRICHER_PHPDOCUMENTOR = 'json_schema_extractor.enricher.phpdocumentor';
    private const SERVICE_COMMAND_EXTRACT = 'json_schema_extractor.command.extract';
    private const TAG_EXTRACTOR = 'json_schema_extractor.extractor';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RegisterOptionalIntegrationsPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $rootNode = $definition->rootNode();
        $children = $this->rootNodeChildren($rootNode);
        $children->scalarNode('default_extractor')->isRequired()->cannotBeEmpty();

        $extractorPrototype = $children
            ->arrayNode('extractors')
            ->useAttributeAsKey('name')
            ->arrayPrototype();

        $extractorChildren = $extractorPrototype->children();
        $extractorChildren->scalarNode('discoverer')->defaultValue(self::SERVICE_DISCOVERER_REFLECTION);
        $extractorChildren
            ->arrayNode('enrichers')
            ->defaultValue([])
            ->scalarPrototype();
        $extractorChildren->scalarNode('serialization')->isRequired()->cannotBeEmpty();
        $extractorChildren->scalarNode('mapper')->defaultValue(self::SERVICE_MAPPER_STANDARD_JSON_SCHEMA);

        $rootNode
            ->validate()
            ->always(static function (array $config): array {
                $defaultExtractor = $config['default_extractor'] ?? null;
                if (!\is_string($defaultExtractor) || !\array_key_exists($defaultExtractor, $config['extractors'] ?? [])) {
                    throw new InvalidConfigurationException(\sprintf(
                        'The default extractor "%s" is not configured under "extractors".',
                        (string) $defaultExtractor
                    ));
                }

                return $config;
            });
    }

    private function rootNodeChildren(object $rootNode): NodeBuilder
    {
        if (!method_exists($rootNode, 'children')) {
            throw new \LogicException('The json_schema_extractor root configuration node must support children.');
        }

        return $rootNode->children();
    }

    /**
     * @param array{
     *     default_extractor: string,
     *     extractors?: array<string, array{
     *         discoverer?: string,
     *         enrichers?: list<string>,
     *         serialization?: string,
     *         mapper?: string
     *     }>
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $this->configureDefaults($services);
        $this->registerDiscoverers($services);
        $this->registerEnrichers($services);
        $this->registerSerializationStrategies($services);
        $this->registerMappers($services);
        $this->registerConfiguredExtractors($services, $config);
        $this->registerConsoleCommands($services, $config);
    }

    private function configureDefaults(ServicesConfigurator $services): void
    {
        $services
            ->defaults()
            ->autowire()
            ->autoconfigure()
            ->public();
    }

    private function registerDiscoverers(ServicesConfigurator $services): void
    {
        $services
            ->set(self::SERVICE_DISCOVERER_REFLECTION, ReflectionDiscoverer::class);

        $this->aliasService($services, self::SERVICE_DISCOVERER_REFLECTION, [
            ReflectionDiscoverer::class,
            DiscovererInterface::class,
        ]);
    }

    private function registerEnrichers(ServicesConfigurator $services): void
    {
        if (class_exists(ParserConfig::class)) {
            $services
                ->set(self::SERVICE_ENRICHER_PHPSTAN, PhpStanEnricher::class);
        }

        if (class_exists(DocBlockFactory::class)) {
            $services
                ->set(self::SERVICE_ENRICHER_PHPDOCUMENTOR, PhpDocumentorEnricher::class);
        }
    }

    private function registerSerializationStrategies(ServicesConfigurator $services): void
    {
        $services
            ->set(self::SERVICE_SERIALIZATION_JSON_ENCODE, JsonEncodeSerializationStrategy::class);

        $this->aliasService($services, self::SERVICE_SERIALIZATION_JSON_ENCODE, [
            JsonEncodeSerializationStrategy::class,
        ]);
    }

    private function registerMappers(ServicesConfigurator $services): void
    {
        $services
            ->set(self::SERVICE_MAPPER_STANDARD_JSON_SCHEMA_OPTIONS, StandardJsonSchemaMapperOptions::class);

        $services
            ->set(self::SERVICE_MAPPER_STANDARD_JSON_SCHEMA, StandardJsonSchemaMapper::class)
            ->arg('$options', service(self::SERVICE_MAPPER_STANDARD_JSON_SCHEMA_OPTIONS));

        $this->aliasService($services, self::SERVICE_MAPPER_STANDARD_JSON_SCHEMA, [
            StandardJsonSchemaMapper::class,
            JsonSchemaMapperInterface::class,
        ]);
    }

    /**
     * @param array{
     *     default_extractor: string,
     *     extractors?: array<string, array{
     *         discoverer?: string,
     *         enrichers?: list<string>,
     *         serialization?: string,
     *         mapper?: string
     *     }>
     * } $config
     */
    private function registerConfiguredExtractors(ServicesConfigurator $services, array $config): void
    {
        foreach ($config['extractors'] ?? [] as $name => $extractorConfig) {
            $serviceId = self::SERVICE_EXTRACTOR_PREFIX . $name;
            $services
                ->set($serviceId, SchemaExtractor::class)
                ->arg('$discoverer', service($extractorConfig['discoverer'] ?? self::SERVICE_DISCOVERER_REFLECTION))
                ->arg('$enrichers', iterator($this->createServiceReferences($extractorConfig['enrichers'] ?? [])))
                ->arg('$serializationStrategy', service($extractorConfig['serialization'] ?? ''))
                ->arg('$mapper', service($extractorConfig['mapper'] ?? self::SERVICE_MAPPER_STANDARD_JSON_SCHEMA))
                ->tag(self::TAG_EXTRACTOR, ['name' => $name]);
        }

        $services->alias(SchemaExtractor::class, self::SERVICE_EXTRACTOR_PREFIX . $config['default_extractor']);
    }

    /**
     * @param array{default_extractor: string} $config
     */
    private function registerConsoleCommands(ServicesConfigurator $services, array $config): void
    {
        if (!class_exists(Command::class)) {
            return;
        }

        $services
            ->set(self::SERVICE_COMMAND_EXTRACT, ExtractJsonSchemaCommand::class)
            ->arg('$extractors', tagged_locator(self::TAG_EXTRACTOR, 'name'))
            ->arg('$defaultExtractorName', $config['default_extractor']);
    }

    /**
     * @param list<string> $serviceIds
     * @return list<ReferenceConfigurator>
     */
    private function createServiceReferences(array $serviceIds): array
    {
        return array_map(
            fn(string $serviceId): ReferenceConfigurator => service($serviceId),
            $serviceIds
        );
    }

    /**
     * @param list<string> $aliases
     */
    private function aliasService(ServicesConfigurator $services, string $serviceId, array $aliases): void
    {
        foreach ($aliases as $alias) {
            $services->alias($alias, $serviceId);
        }
    }
}
