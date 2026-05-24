# Symfony Bundle

> Optional Symfony wiring for configured extractor pipelines.

## Dependencies

- `symfony/http-kernel`
- `symfony/config`
- `symfony/dependency-injection`

The debug command also requires:

- `symfony/console`

Depending on the components used by your extractors, you may also need their optional integration packages.

Supported Symfony versions follow the constraints declared in `composer.json`.

## When to use it

Use the bundle when your application already uses Symfony's container and you want to assemble one or more named `SchemaExtractor` pipelines from services.

The bundle does not guess an extractor pipeline automatically. Each extractor represents an application-level choice: which metadata sources are trusted, which serialization strategy describes the JSON payload, and which mapper should produce the final schema.

## Minimal configuration

`serialization` is required because the bundle cannot infer how your application produces JSON payloads. Use `json_schema_extractor.serialization.symfony_serializer` when the schema should follow Symfony Serializer metadata.

```yaml
# config/packages/json_schema_extractor.yaml
json_schema_extractor:
  default_extractor: api

  extractors:
    api:
      serialization: json_schema_extractor.serialization.symfony_serializer
```

With this configuration the bundle uses:

| Option       | Default behavior                                                                 |
|:-------------|:---------------------------------------------------------------------------------|
| `discoverer` | Uses the reflection discoverer to read PHP class properties and native types.    |
| `mapper`     | Uses the standard JSON Schema mapper to produce the final JSON Schema document.  |
| `enrichers`  | No enrichers are enabled unless explicitly listed.                               |

The configured extractor is registered as:

```text
json_schema_extractor.extractor.api
```

`default_extractor` is required and must point to one of the configured extractors. It defines the main application extractor: `SchemaExtractor::class` is aliased to it, and the debug command uses it when no `--extractor` option is passed.

## Extractor configuration

Extractor options are service ids.

```yaml
json_schema_extractor:
  default_extractor: api

  extractors:
    api:
      discoverer: json_schema_extractor.discoverer.reflection
      enrichers:
        - json_schema_extractor.enricher.phpstan
      serialization: json_schema_extractor.serialization.symfony_serializer
      mapper: json_schema_extractor.mapper.standard_json_schema
```

Use the built-in service ids when they fit, or provide your own services when you need custom behavior.

## Multiple extractors

You can configure multiple pipelines for different payload contracts:

```yaml
json_schema_extractor:
  default_extractor: public_api

  extractors:
    public_api:
      enrichers:
        - json_schema_extractor.enricher.phpstan
      serialization: json_schema_extractor.serialization.symfony_serializer

    admin_api:
      serialization: App\Schema\AdminSerializationStrategy
```

Each configured extractor becomes a service. The extractor name is used as the suffix of its service id:

```text
json_schema_extractor.extractor.public_api
json_schema_extractor.extractor.admin_api
```

`default_extractor` must point to one of the configured extractors.

## Symfony Serializer

Use `json_schema_extractor.serialization.symfony_serializer` when the schema must describe the payload produced by Symfony Serializer:

```yaml
json_schema_extractor:
  default_extractor: api

  extractors:
    api:
      enrichers:
        - json_schema_extractor.enricher.phpstan
      serialization: json_schema_extractor.serialization.symfony_serializer
```

The bundle registers this strategy only when Symfony's `serializer.mapping.class_metadata_factory` service is available. If `serializer.name_converter` exists, it is passed to the strategy as well.

For runtime serializer context such as groups, pass a `SymfonySerializerContext` when calling `SchemaExtractor::extract()`. See [`docs/serialization/symfony-serializer.md`](serialization/symfony-serializer.md).

## Debug command

When `symfony/console` is installed, the bundle registers:

```bash
bin/console json-schema-extractor:extract 'App\Dto\User'
```

The command is meant for inspection and debugging. It runs the configured application pipeline and prints the generated JSON Schema.

Use `--extractor` to select a non-default pipeline:

```bash
bin/console json-schema-extractor:extract 'App\Dto\User' --extractor=admin_api
```

Use `--compact` for one-line JSON output:

```bash
bin/console json-schema-extractor:extract 'App\Dto\User' --compact
```

## Registered services

The bundle registers built-in component services only when their optional dependencies are available.

| Service id                                                  | Component                         |
|:------------------------------------------------------------|:----------------------------------|
| `json_schema_extractor.discoverer.reflection`               | `ReflectionDiscoverer`            |
| `json_schema_extractor.mapper.standard_json_schema`         | `StandardJsonSchemaMapper`        |
| `json_schema_extractor.serialization.json_encode`           | `JsonEncodeSerializationStrategy` |
| `json_schema_extractor.serialization.symfony_serializer`    | `SymfonySerializerStrategy`       |
| `json_schema_extractor.enricher.phpstan`                    | `PhpStanEnricher`                 |
| `json_schema_extractor.enricher.phpdocumentor`              | `PhpDocumentorEnricher`           |
| `json_schema_extractor.enricher.symfony_validator`          | `SymfonyValidationEnricher`       |

Configured extractors are registered as `json_schema_extractor.extractor.<name>`.

## Custom services

Use normal Symfony services for custom behavior:

```yaml
services:
  App\Schema\AppSerializerStrategy:
    arguments:
      $inner: '@json_schema_extractor.serialization.symfony_serializer'

json_schema_extractor:
  default_extractor: api

  extractors:
    api:
      serialization: App\Schema\AppSerializerStrategy
      enrichers:
        - json_schema_extractor.enricher.phpstan
```

The same applies to any configurable component in an extractor pipeline.

## Without the bundle

The same pipeline can be wired manually with regular Symfony services:

```yaml
services:
  Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer: ~

  Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher: ~

  Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapper: ~

  Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy:
    arguments:
      $classMetadataFactory: '@serializer.mapping.class_metadata_factory'
      $nameConverter: '@?serializer.name_converter'

  Zeusi\JsonSchemaExtractor\SchemaExtractor:
    arguments:
      $discoverer: '@Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer'
      $enrichers:
        - '@Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher'
      $serializationStrategy: '@Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy'
      $mapper: '@Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapper'
```

The bundle mainly reduces boilerplate around optional integrations, provides stable service ids, creates named extractors from configuration, and exposes the debug command.

## Limitations

- The bundle does not guess enrichers. Configure them explicitly and in the order you want them to run.
- `serialization` is required for each extractor.
- Component options are configured through normal services, not through bundle configuration.
- The command is an inspection tool, not a replacement for application-specific schema publishing workflows.
