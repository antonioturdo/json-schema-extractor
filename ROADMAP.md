# Roadmap

This project is currently in **feature freeze** for the first stable release.

## Post-v1 Ideas (Deferred)

These are intentionally postponed until after the first stable release:
- Public override mechanism for union semantics:
  - Attribute for simple property-level overrides
  - Resolver/callback or configurable enricher for nested unions, PHPDoc shapes, vendor classes, and generated code
- Attribute-based override for field requiredness:
  - Useful as an escape hatch for real PHP properties when validator/serializer metadata is not enough
  - Not a general solution for inline object fields, which should keep deriving requiredness from their source metadata
- Serializer-aware default value normalization:
  - Distinguish PHP default values from JSON/schema default values
  - Consider a callable/context/enricher that can normalize object defaults using the same serialization strategy as runtime output
- PHP core temporal object shapes:
  - `DateTimeInterface` is currently mapped to its native `json_encode()` object shape
  - Evaluate equivalent native `json_encode()` mappings for `DateTimeZone` and `DateInterval`
  - Defer `DatePeriod` until there is a clear use case, because its serialized shape is more nested and less common
- Schema generation cache:
  - Add a core cache extension point for generated schemas, keyed by class name plus relevant options/context
  - Prefer caching the final schema output first, because it is simpler and safer than caching the internal IR
  - Consider an in-memory cache for repeated generation within the same process/request
  - In a future Symfony bundle, consider cache warmup that writes PHP files under `var/cache/...` so OPcache can cache precomputed schemas
  - Revisit IR/ClassDefinition caching only if schema-output caching is not flexible enough
- Investigate Symfony Serializer normalizers and how they affect generated schema shapes:
  - https://symfony.com/doc/current/serializer.html#serializer-normalizers
  - Consider explicit normalizer shape overrides for types whose serialized representation differs from their PHP structure
  - Explore `JsonSerializableNormalizer` support only through explicit shape metadata/overrides or PHPDoc on `jsonSerialize()`; do not infer it automatically from `JsonSerializable` alone
  - Evaluate optional `FormErrorNormalizer` support for `symfony/form` (`FormInterface` -> RFC 7807-style error shape)
  - Evaluate Symfony 7.x `NumberNormalizer` support for `BcMath\Number`/`GMP` values once it can be tested locally
- Evaluate explicit JSON Schema dialect support:
  - Decide whether to declare `$schema`
  - Validate generated schemas against Draft-7, 2019-09, and/or 2020-12 metaschemas
  - Review dialect-specific keywords before claiming support beyond the current output subset
- Review `Schema` reference modeling:
  - Today `Schema` can represent both `$ref` schemas and regular schemas
  - Serialization treats `$ref` as reference-only and ignores sibling keywords
  - Consider explicit reference schema modeling or guarded factories/setters to avoid inconsistent internal states
- Review `ClassDefinition` field storage:
  - `ClassDefinition` currently keeps class-backed properties and virtual fields in separate collections
  - Other object-shape definitions expose a single property collection through `ObjectShapeDefinitionTrait`
  - This makes object-shape storage inconsistent across definition types
- Composition & inheritance (`allOf`/`oneOf` strategies beyond Symfony discriminator-driven polymorphism)
- Fluent schema builder API (manual schema construction without PHP classes)
