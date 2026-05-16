# JSON Schema Extractor

[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg)](https://www.php.net/)
[![Packagist Version](https://img.shields.io/packagist/v/zeusi/json-schema-extractor.svg)](https://packagist.org/packages/zeusi/json-schema-extractor)
[![PHPUnit](https://img.shields.io/badge/phpunit-10.5-366488.svg)](https://phpunit.de/)
[![PHPStan](https://img.shields.io/badge/phpstan-level%208-brightgreen.svg)](https://phpstan.org/)

> JSON Schema Extractor generates JSON Schema documents from PHP DTOs by extracting native types, PHPDoc, validation constraints, and serialization metadata through a modular pipeline.

Typical use cases include:

- structured output definitions for LLMs
- reusable DTO schemas for a broader AsyncAPI documentation flow

## Features

- **No mandatory dependencies**: install only the optional packages needed by the components you enable.
- **Existing metadata first**: builds schemas from native PHP types, PHPDoc, Symfony Validator, and serializer metadata without requiring schema-specific attributes.
- **PHPDoc-aware enrichment**: reads PHPDoc through the PHPStan or phpDocumentor based enrichers, covering generics, shaped arrays, literals, ranges, enums, nullable types, and text metadata.
- **Symfony integration**: maps supported Symfony Validator constraints and projects output shapes through Symfony Serializer metadata such as groups, serialized names, name converters, discriminators, and known normalizers.
- **Reference-safe object graphs**: handles nested DTOs, reusable definitions, enums, and circular references through `$ref`.
- **Serialization-aware output**: separates the PHP model from the JSON-facing shape, with built-in strategies for PHP `json_encode()` and Symfony Serializer.

## Installation

```bash
composer require zeusi/json-schema-extractor
```

The core package has no mandatory dependencies. Some components need optional packages depending on the metadata source you want to use:

```bash
composer require phpstan/phpdoc-parser
composer require phpdocumentor/reflection-docblock
composer require symfony/validator
composer require symfony/serializer
```

Install only the packages needed by the discoverers, enrichers, or serialization strategies you enable.

## Quick Example

Given a small DTO:

```php
final class UserProfile
{
    public function __construct(
        public string $id,

        /** @var list<string> */
        public array $roles = [],

        /** @var array{theme: string, notifications: bool, locale?: string} */
        public array $preferences = [],
    ) {}
}
```

Create an extractor:

```php
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;

$extractor = new SchemaExtractor(
    new ReflectionDiscoverer(setTitleFromClassName: true),
    [
        new PhpStanEnricher(),
    ],
    new JsonEncodeSerializationStrategy(),
    new StandardSchemaMapper(),
);

$schema = $extractor->extract(UserProfile::class);

echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

The resulting schema includes native PHP types from reflection and PHPDoc information read by the enabled enricher, such as list item types and shaped array fields:

```json
{
  "type": "object",
  "title": "UserProfile",
  "properties": {
    "id": {
      "type": "string"
    },
    "roles": {
      "type": "array",
      "items": {
        "type": "string"
      },
      "default": []
    },
    "preferences": {
      "type": "object",
      "properties": {
        "theme": {
          "type": "string"
        },
        "notifications": {
          "type": "boolean"
        },
        "locale": {
          "type": "string"
        }
      },
      "required": [
        "theme",
        "notifications"
      ]
    }
  },
  "additionalProperties": false
}
```

For executable examples, see `bin/schema.php`.

## Architecture

Extraction is intentionally split into separate phases:

1. **Discover** the PHP class model
2. **Enrich** that PHP model with metadata from PHPDoc, attributes, validators, or custom sources
3. **Project** the enriched PHP model into the serialized output shape
4. **Map** the serialized output shape to JSON Schema

When instantiating [`SchemaExtractor`](src/SchemaExtractor.php),
choose components according to the metadata you trust and the serializer you actually use at runtime.

Custom behavior can be added by implementing a discoverer, enricher, serialization strategy, or mapper.

## Components

### Discoverer

[`ReflectionDiscoverer`](src/Discoverer/ReflectionDiscoverer.php) is the default and currently only discoverer. It creates the baseline PHP model by reading class properties and native PHP types through reflection, optionally using the class short name as the schema title.

To create a custom discoverer implement [`DiscovererInterface`](src/Discoverer/DiscovererInterface.php).

### Enrichers

Enrichers mutate the PHP model before serialization projection. They should merge information where possible and avoid overwriting stronger information with weaker inferred metadata.
They run sequentially, so their order can still matter when two enrichers write to the same part of the model.

| Component                   | Use it when                                                                                                                   | Documentation                                                                  |
|:----------------------------|:------------------------------------------------------------------------------------------------------------------------------|:-------------------------------------------------------------------------------|
| `PhpStanEnricher`           | To extract high-precision PHPDoc types, including shaped arrays, generics, ranges, and literal types.                         | [`docs/enrichers/phpstan.md`](docs/enrichers/phpstan.md)                       |
| `PhpDocumentorEnricher`     | To extract DocBlock summaries, descriptions, examples, deprecation, and PHPDoc types via `phpdocumentor/reflection-docblock`. | [`docs/enrichers/phpdocumentor.md`](docs/enrichers/phpdocumentor.md)           |
| `SymfonyValidationEnricher` | You want Symfony Validator constraints reflected in the schema as requiredness, formats, limits, and collection shapes.       | [`docs/enrichers/symfony-validation.md`](docs/enrichers/symfony-validation.md) |

For a side-by-side capability matrix of the two PHPDoc enrichers, see [`docs/enrichers/phpdoc-enricher-comparison.md`](docs/enrichers/phpdoc-enricher-comparison.md).

To create a custom enricher implement [`EnricherInterface`](src/Enricher/EnricherInterface.php).

### Serialization Strategies

Serialization strategies convert the enriched PHP model into the JSON-facing shape consumed by the mapper. They are the right place for output field names, omitted fields, discriminator fields, and serializer-specific normalized types.

| Component                         | Use it when                                                                                                                      | Documentation                                                                          |
|:----------------------------------|:---------------------------------------------------------------------------------------------------------------------------------|:---------------------------------------------------------------------------------------|
| `JsonEncodeSerializationStrategy` | Your JSON payload follows PHP `json_encode()` behavior.                                                                          | [`docs/serialization/json-encode.md`](docs/serialization/json-encode.md)               |
| `SymfonySerializerStrategy`       | Your JSON payload is produced by Symfony Serializer, including groups, serialized names, name converters, and known normalizers. | [`docs/serialization/symfony-serializer.md`](docs/serialization/symfony-serializer.md) |

To create a custom serialization strategy implement [`SerializationStrategyInterface`](src/Serialization/SerializationStrategyInterface.php).

### Mapper

[`StandardSchemaMapper`](src/Mapper/StandardSchemaMapper.php) is the default and currently only mapper. It converts the serialized model to Draft-7 JSON Schema.
By default, class-backed DTOs and enums are collected under `definitions` and referenced with `$ref`.
If you prefer those schemas to be expanded at the usage site, configure the mapper with the inline class reference strategy.

Nested objects and complex structures are handled recursively. With [`ClassReferenceStrategy::Definitions`](src/Mapper/ClassReferenceStrategy.php), class-backed types are emitted once under `definitions` and reused through `$ref`. Circular references are always broken with `$ref`.

To create a custom mapper implement [`SchemaMapperInterface`](src/Mapper/SchemaMapperInterface.php).

## Schema Semantics

### `additionalProperties`

Class-based object schemas are strict by default: [`SchemaExtractorOptions`](src/SchemaExtractorOptions.php) defaults to `additionalProperties: false`.
This is meant to make DTO-shaped schemas describe the known serialized payload, not an open-ended object.

You can override that default per class with [`#[AdditionalProperties(true)]`](src/Attribute/AdditionalProperties.php).
Inline object shapes can also carry their own `additionalProperties` value, for example shaped dictionaries or Symfony Validator `Collection` constraints.

Dictionary-style PHPDoc types such as `array<string, T>` are represented as JSON objects whose `additionalProperties` value is the schema for `T`.

### `required`

`required` means that a field is expected to be present in the serialized payload. It does not mean that the field value cannot be `null`.
The extractor does not infer `required` from PHP non-nullable types alone; enrichers and serialization strategies can mark fields as required or optional when their metadata describes payload presence.

## Limitations

- Interfaces and abstract/base classes need an explicit strategy, such as Symfony discriminator metadata, to identify possible concrete output shapes
- Some JSON Schema decisions are necessarily conservative. For example, unions are collapsed when safe, but `oneOf` versus `anyOf` cannot always be inferred from PHP types alone

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
