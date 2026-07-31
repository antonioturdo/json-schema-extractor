# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - 2026-07-31

### Fixed

- An unresolved type (`UnknownType`) could overwrite an already-resolved type
  during declared-type merging, at the top level or nested inside an array/map.
  This made enricher composition order-dependent: an enricher that failed to
  resolve a name (for example `PhpStanEnricher` on a `use`-aliased type imported
  from another namespace) could clobber the type a previous enricher had already
  resolved. `UnknownType` is now treated as the bottom of the type lattice — it
  never degrades a resolved type, and any concrete type may upgrade it — so
  composing `PhpStanEnricher` with `PhpDocumentorEnricher` resolves such types
  regardless of the order the enrichers run in.

## [2.0.0] - 2026-06-06

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

[2.0.1]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/2.0.1
[2.0.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/2.0.0
[1.3.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/1.3.0
[1.2.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/1.2.0
[1.1.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/1.1.0
[1.0.0]: https://github.com/antonioturdo/json-schema-extractor/releases/tag/1.0.0
