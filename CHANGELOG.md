# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-06-02

### Added

- Support for Symfony Serializer `AbstractNormalizer::ATTRIBUTES`. The serializer
  context now both filters which attributes are serialized and narrows the nested
  view of each class-backed property, projected inline as a bespoke shape.

### Changed

- **BREAKING** — `SerializationStrategyInterface` changed:
  - a new `initialState(ExtractionContext): ProjectionState` method is required;
  - `project()` now takes a third argument, `ProjectionState $state`.
- **BREAKING** — `JsonSchemaMapperInterface::map()` now takes a single
  `SerializedProjection` argument instead of a `SerializedPayloadDefinition`
  plus a `callable` payload provider. The mapper is now a pure fold over a
  fully resolved projection (no re-entrant callback).

> These breaking changes affect **only code that implements those interfaces**
> — custom serialization strategies or mappers. Standard usage through the
> built-in components (`JsonEncodeSerializationStrategy`,
> `SymfonySerializerStrategy`, `StandardJsonSchemaMapper`) and `SchemaExtractor`
> is unchanged and requires no migration.

## Migrating a custom serialization strategy

Add `initialState()` and the `$state` parameter. If your strategy has no
path-dependent behaviour, return the neutral state and ignore the argument:

```php
use Zeusi\JsonSchemaExtractor\Serialization\State\NeutralProjectionState;
use Zeusi\JsonSchemaExtractor\Serialization\State\ProjectionState;

public function initialState(ExtractionContext $context): ProjectionState
{
    return NeutralProjectionState::instance();
}

public function project(
    ClassDefinition $definition,
    ExtractionContext $context,
    ProjectionState $state,            // new argument
): SerializedPayloadDefinition {
    // unchanged body
}
```

## Migrating a custom mapper

`map()` now receives a resolved projection. Get the root payload from it, and
resolve nested class-backed views through it instead of calling the old provider:

```php
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedProjection;
use Zeusi\JsonSchemaExtractor\Model\Serialized\ViewId;

// Before:
// public function map(SerializedPayloadDefinition $definition, callable $payloadProvider): JsonSchemaInterface
// {
//     $rootType = $definition->type;
//     $nested = $payloadProvider($someClassName); // SerializedPayloadDefinition
// }

// After:
public function map(SerializedProjection $projection): JsonSchemaInterface
{
    $rootType = $projection->rootPayload()->type;
    $nested = $projection->get(new ViewId($someClassName)); // SerializedPayloadDefinition
}
```

## [1.3.0] - 2026-05-31

### Added

- Support for the Symfony Serializer ignored attributes context
  (`AbstractNormalizer::IGNORED_ATTRIBUTES`).

## [1.2.0] - 2026-05-24

### Added

- Symfony bundle configuration support.

### Documentation

- Documented Symfony Serializer runtime limitations.

## [1.1.0] - 2026-05-22

### Added

- Symfony Serializer `FormErrorNormalizer` and `NumberNormalizer` mappings.

### Documentation

- Added Symfony Serializer runtime customization docs.

## [1.0.0] - 2026-05-21

- Initial stable release: generates JSON Schema documents from PHP DTOs through a
  modular pipeline (reflection-based discovery, PHPDoc enrichment, Symfony Validator
  constraints, serialization projection), with nested objects, enums, unions,
  circular references, and Draft-7 / Draft 2020-12 output.

[2.0.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/2.0.0
[1.3.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/1.3.0
[1.2.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/1.2.0
[1.1.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/1.1.0
[1.0.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/1.0.0
