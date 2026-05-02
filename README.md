# Modular PHP JSON Schema Generator

A high-performance, modular JSON Schema generator for PHP 8.1+. This library allows you to generate standard JSON Schema (Draft-7) from your PHP classes using a flexible pipeline of discovery, enrichment, and mapping.

## Features

- **Modular Pipeline**: Easily extend the generation process with custom Discoverers, Enrichers, and Mappers.
- **Rich Metadata**: Supports PHPDoc (summary, description, examples, deprecation) and advanced types.
- **Symfony Integration**: Optional deep integration with Symfony Validator and Serializer.
- **Recursive Support**: Automatically handles nested objects and complex structures, with circular references represented using `$ref`.
- **Reference Strategy**: Named PHP types (DTO classes and enums) are collected under `definitions` by default and referenced with `$ref`.
- **Enricher Pipeline**: Complete control over the generation flow. Note that the **order of enrichers matters**, as they process the schema sequentially and can override previous values.
- **Context-Aware**: Choose enrichers based on your serialization strategy. For example, use the `SerializerPropertyEnricher` if you use the Symfony Serializer to ensure property names and groups match your actual JSON output.
- **Strict by Default**: Class-based schemas are generated with `additionalProperties: false` by default (you can override this per-class or via generator configuration).

## Installation

```bash
composer require antonioturdo/json-schema-generator
```

## Architecture

The library follows a 3-step pipeline to ensure maximum extensibility:

1.  **Discoverer**: Scans the class and identifies properties.
2.  **Enricher**: Enriches the properties with metadata from various sources (DocBlocks, Attributes, etc.).
3.  **Mapper**: Converts the internal representation into a standard JSON Schema structure.

### Pick enrichers that match your serializer

In practice, “what the JSON looks like” depends on your serialization strategy (e.g. Symfony Serializer groups, name conversion, skipping nulls). A pipeline that includes `SerializerPropertyEnricher` is correct only if your runtime serialization matches its assumptions.

- If you serialize with **Symfony Serializer**, include `SerializerPropertyEnricher` so schema keys match your serialized output.
- If you do **not** serialize with Symfony Serializer, you should not include it (it can rename/exclude fields in ways that don’t match your output).

### Generation Context

To make pipelines harder to misconfigure, you can pass a **Generation Context** (a structured object describing your serialization environment and policies) to `generate()`. Enrichers can then read the same serializer context options you pass to Symfony Serializer.

```php
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

$context = (new GenerationContext())
    ->with(new SymfonySerializerContext(context: [
        AbstractNormalizer::GROUPS => ['read'],
        AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
    ]));

// You can add more capabilities here (your own objects) for custom enrichers.

$schema = $generator->generate(MyDto::class, $context);
```

## Basic Usage

```php
use Zeusi\JsonSchemaGenerator\SchemaGenerator;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaGenerator\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaGenerator\SchemaGeneratorOptions;

$generator = new SchemaGenerator(
    new ReflectionPropertyDiscoverer(),
    [
        new PhpDocumentorEnricher(),
    ],
    new StandardSchemaMapper(),
    new SchemaGeneratorOptions(defaultAdditionalProperties: false),
);

$schema = $generator->generate(MyUser::class);
echo json_encode($schema, JSON_PRETTY_PRINT);
```

### Class references

By default, the standard mapper treats named PHP types (class-backed DTOs and enums) as reusable definitions. Properties referencing those types are emitted as `$ref`, while the referenced schema is collected under `definitions`.

```php
use Zeusi\JsonSchemaGenerator\Mapper\ClassReferenceStrategy;
use Zeusi\JsonSchemaGenerator\Mapper\StandardSchemaMapper;

$mapper = new StandardSchemaMapper(ClassReferenceStrategy::Definitions);
```

This is also the recommended mode for recursive object graphs: self-references can point to the root schema (`#`) or to a named definition.

If you prefer class-backed DTOs to be expanded where they are referenced, opt into the inline strategy:

```php
$mapper = new StandardSchemaMapper(ClassReferenceStrategy::Inline);
```

Inline object shapes, such as PHPStan shaped arrays and Symfony Validator `Collection` fields, always remain nested inline because they do not have a reusable PHP class identity.

### additionalProperties strictness

By default, this library sets `additionalProperties: false` on class-based object schemas.
You can override this behaviour per-class with an attribute:

```php
use Zeusi\JsonSchemaGenerator\Attribute\AdditionalProperties;

#[AdditionalProperties(true)]
final class MyOpenDto
{
    public string $id;
}
```

### Dictionaries

String-keyed arrays documented as `array<string, T>` are mapped as JSON objects whose values match `T`.

```php
final class Response
{
    /** @var array<string, string> */
    public array $headers = [];
}
```

This produces a property shaped like:

```json
{
  "type": "object",
  "additionalProperties": {
    "type": "string"
  }
} 
```

### Date/time values

Without serializer-specific enrichers, `DateTimeInterface` values are mapped to the shape produced by PHP's `json_encode()` for `DateTime` objects: an object with `date`, `timezone_type`, and `timezone`.

If your runtime serializer normalizes dates differently, include an enricher that matches that serialization strategy.

### Current boundaries

Some schema details depend on conventions that PHP types alone cannot express:

- A native PHP `array` does not say whether the runtime value is a list or a dictionary; use PHPDoc like `array<int, T>` or `array<string, T>` and enable a PHPDoc enricher that can read it when the distinction matters.
- `required` describes whether a field is expected to be present in the serialized payload, not whether its value can be `null`. The generator does not infer `required` from PHP non-nullable types by default; enrichers such as `SymfonyValidationEnricher` can mark fields as required from validation metadata, while serializer-aware enrichers may relax that when the field can be omitted.
- Interface and base-type schemas need an explicit strategy, such as a Symfony discriminator map, to know which concrete classes may appear.
- Primitive unions are collapsed to JSON Schema `type` arrays when possible; `oneOf` vs `anyOf` cannot always be inferred safely for object/class unions unless the branches are known to be mutually exclusive or the intent is made explicit.

---

### ReflectionPropertyDiscoverer

The base discovery layer uses native PHP Reflection to extract properties and types.

**Supported Features:**
- **Native Types**: Full support for PHP 8.0+ types including Union types (`string|int`) and Intersection types.
- **Enums**: Native PHP 8.1 Enum support. It automatically detects Backed Enums and maps them to their scalar types (`string` or `int`) with a corresponding `enum` constraint in the schema.
- **Default Values**: Automatically extracts default values from:
    - Standard property declarations.
    - **Promoted Constructor Properties** (PHP 8.0+).
    - Enum cases (mapped to their scalar value).
- **Nullability**: Correctly identifies nullable types and maps them to JSON Schema `oneOf` or `nullable` patterns.

---

## Available Enrichers

> [!NOTE]
> This library is modular. You must install the **Dependencies** indicated in the table below for each enricher you wish to use in your pipeline.

| Enricher | Role | Dependencies |
| :--- | :--- | :--- |
| `PhpStanEnricher` | High-precision parsing with support for **Shaped Arrays** and complex generics. | `phpstan/phpdoc-parser` |
| `PhpDocumentorEnricher` | Extracts metadata and advanced types (pseudo-types) from PHPDoc. | `phpdocumentor/reflection-docblock` |
| `SymfonyPhpDocPropertyEnricher` | Standard Symfony abstraction for PHPDoc metadata. | `symfony/property-info` |
| `SymfonyValidationEnricher` | Maps Symfony Validation constraints to JSON Schema boundaries. | `symfony/validator` |
| `SerializerPropertyEnricher` | Handles property renaming and exclusion groups. | `symfony/serializer` |

---

## Choosing the right PHPDoc Enricher

The library provides three different ways to extract metadata from PHPDocs. Choose the one that best fits your project requirements:

For a detailed behavior matrix generated from fixtures, see [`docs/phpdoc-enricher-capabilities.md`](docs/phpdoc-enricher-capabilities.md).

| Feature | `PhpStanEnricher` | `PhpDocumentorEnricher` | `SymfonyPhpDocPropertyEnricher` |
| :--- | :---: | :---: | :---: |
| **Shaped Arrays** | ✅ Yes | ❌ No | ❌ No |
| **Advanced Generics** | ✅ High | ⚠️ Partial | ⚠️ Partial |
| **Pseudo-types** | ✅ Full | ✅ Full | ❌ No |
| **Summary/Description split** | ❌ No | ✅ Yes | ✅ Yes |
| **Stability** | 🚀 High | ✅ High | ✅ High |
| **Speed** | ⚡ Fast | ⚡ Fast | ⚠️ Slower (Abstraction layer) |

---

### PhpStanEnricher
*Powered by `phpstan/phpdoc-parser`*

The ultimate enricher for high-precision type extraction. It bridges the gap between PHP's static analysis types and complex JSON Schema structures.

**Pros:**
- **Superior Precision**: Supports **Shaped Arrays** (`array{key: type}`), translating them into nested JSON objects.
- **Modern Syntax**: Best support for advanced generics and nested collections.
- **Native logic**: Does not depend on large abstraction layers.

**Cons:**
- Requires `phpstan/phpdoc-parser` as an extra dependency.

**Supported Features:**
- **Shaped Arrays**: Full support for `array{id: int, name: string}`. Optional keys (`key?: type`) are correctly identified.
- **Generics & Collections**:
    - **Typed Arrays**: Support for `T[]` and `array<T>` notation.
    - **Nested Collections**: Handles complex nesting like `array<array{id: int}>`.
    - **Recursive Resolution**: Automatically triggers recursive schema generation for complex objects within collections.
- **Advanced Pseudo-Types**:
    - `positive-int`, `negative-int`
    - `int<min, max>` (e.g., `int<1, 100>`)
    - `non-empty-string`, `non-empty-array`
- **Metadata**: Extracts `@var` types and simple tags like `@example` and `@deprecated`. For free-text PHPDoc, it populates only `description` (no summary/title split).

---

### PhpDocumentorEnricher
*Powered by `phpdocumentor/reflection-docblock`*

A robust and widely used enricher for standard PHPDoc metadata. Ideal for projects that follow historical documentation standards.

**Pros:**
- **Stability**: Built on the long-standing standard for PHP documentation.
- **Rich Metadata**: Excellent support for summaries, descriptions, and standard tags like `@example` or `@deprecated`.

**Cons:**
- No support for shaped arrays.
- Less precise than PHPStan for complex nested generics.

**Supported Features:**
- **Generics & Collections**:
    - **Typed Arrays**: Support for `T[]` and `array<T>` notation.
    - **Generic Unions**: Support for union types within collections, like `array<StatusEnum|string>`.
    - **Recursive Resolution**: Automatically triggers recursive schema generation for complex objects within collections.
- **Advanced Pseudo-Types**:
    - `positive-int`, `negative-int`
    - `int<min, max>` (e.g., `int<1, 100>`)
    - `non-empty-string`, `non-empty-array`
- **Metadata**: Full extraction of `@var` types, summary, description, `@example`, and `@deprecated`.
- **Multi-tag support**: Combine multiple `@var` tags to bypass parser limitations.

---

### SymfonyPhpDocPropertyEnricher
*Powered by `symfony/property-info`*

A lightweight alternative that uses the standard Symfony abstraction layer.

**Pros:**
- **Zero extra dependencies**: Uses the same component Symfony uses for its internal metadata.
- **Consistency**: Guarantees the same type interpretation as other Symfony components (like Form or Validator).

**Cons:**
- **Limited precision**: No support for complex pseudo-types (ranges, non-empty strings) or shaped arrays.

**Supported Features:**
- **Metadata**: Extracts basic `@var` types, summary, and description using Symfony's `PhpDocExtractor`.
- **Generics**: Supports basic `T[]` and `array<T>` notation for simple collections.
- **Recursive Resolution**: Automatically triggers recursive schema generation for complex objects within collections.
- **Integration**: Plugs perfectly into Symfony's standard metadata extraction pipeline.

---

### SymfonyValidationEnricher

Integrates with `symfony/validator` to translate PHP validation rules into JSON Schema constraints.

**Mapped Constraints:**
- **Basic**: `NotBlank`, `NotNull` -> `required`
- **Boundaries**: `Length` -> `minLength`, `maxLength`, `Range` -> `minimum`, `maximum`
- **Collections**: `All` -> constraints applied to `items`, `Collection` -> inline object shape with `additionalProperties`
- `Email`, `Url`, `Uuid`, `Ip` -> `format`
- `Regex` -> `pattern`
- `Choice` -> `enum`
- `Positive`, `Negative`, `DivisibleBy` -> numeric constraints

### SerializerPropertyEnricher

Integrates with `symfony/serializer` to ensure the generated schema matches your JSON output.

This enricher also changes `DateTimeInterface` values from the standard `json_encode()` object shape to a JSON Schema `string` with `format: date-time`, matching the date output expected from the Symfony Serializer pipeline.

`SymfonySerializerContext` is optional: when omitted, the enricher behaves as if no serializer context was passed. When present, it receives the same context array you would pass to Symfony Serializer; known options like `groups` and `AbstractObjectNormalizer::SKIP_NULL_VALUES` are interpreted by the enricher.

**Supported Features:**
- **Property Renaming**: Supports `SerializedName` attribute.
- **Groups Support**: Generate different schemas based on serialization groups.
- **Date/time Values**: Maps `DateTimeInterface` values to `type: string` with `format: date-time` by default, and honors known `DateTimeNormalizer::FORMAT_KEY` context values such as `Y-m-d` (`format: date`).
- **Timezone Values**: Maps `DateTimeZone` values to `type: string`, matching Symfony's `DateTimeZoneNormalizer`.
- **Date Interval Values**: Maps `DateInterval` values to `type: string` with `format: duration` for Symfony's default interval format; custom `DateIntervalNormalizer::FORMAT_KEY` values remain plain strings.
- **UID Values**: When `symfony/uid` is installed, maps Symfony `AbstractUid` values to `type: string`. `Uuid` values use `format: uuid` for the canonical/RFC4122 serializer formats; Base32/Base58 outputs remain plain strings.
- **Translatable Values**: Maps `TranslatableInterface` values to `type: string`, matching Symfony's `TranslatableNormalizer`; locale selection does not change the schema shape.
- **Data URI Files**: Maps `SplFileInfo`/`SplFileObject` values to `type: string` with a `^data:` pattern, matching Symfony's `DataUriNormalizer`.
- **Constraint Violation Lists**: When `symfony/validator` is installed, maps `ConstraintViolationListInterface` to Symfony's RFC 7807-style object shape.
- **Problem Details**: When `symfony/error-handler` is installed, maps `FlattenException` to Symfony's RFC 7807-style problem object shape.
- **Class Discriminator**: If a Symfony discriminator map is configured, concrete classes will include the discriminator property (as a required string with an `enum` of the mapped type). Base classes and interfaces will be mapped as `oneOf` of the configured concrete classes, including nested class-like references handled through the normal recursive schema generation flow.

**Known Limits:**
- **JsonSerializable**: `JsonSerializableNormalizer` is not inferred automatically. `jsonSerialize()` can return any runtime-dependent shape and Symfony will further normalize that result, so automatic schema generation would be unreliable without explicit shape metadata.
