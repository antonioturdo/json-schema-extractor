# SymfonySerializerStrategy

> Aligns extracted schema with actual Symfony Serializer output.

## Dependencies

- `symfony/serializer`

Some known-normalizer mappings also depend on the Symfony package that defines the normalized type:

- `symfony/uid` for Symfony UID values.
- `symfony/validator` for `ConstraintViolationListInterface`.
- `symfony/error-handler` for `FlattenException`.
- `symfony/translation-contracts` for `TranslatableInterface`.

Supported Symfony versions follow the constraints declared in `composer.json`.

## When to use it

Use this strategy when your runtime output is produced by Symfony Serializer and schema keys/types must match that serialized payload.

> [!WARNING]
> The generated JSON Schema may not reflect some advanced or custom Symfony Serializer use cases. See the [limitations](#limitations) section for more details.

## Context

When using `SchemaExtractor` you can pass a `SymfonySerializerContext` inside the extraction context:

```php
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;

$context = (new ExtractionContext())->with(new SymfonySerializerContext([
    AbstractNormalizer::GROUPS => ['read'],
]));

$schema = $extractor->extract(Event::class, $context);
```

The context should mirror the normalization context used by your Symfony Serializer at runtime. The strategy does not run the serializer, but it uses the same context keys where they affect the serialized shape.

### Groups

`AbstractNormalizer::GROUPS` controls which properties are projected into the serialized model.

When groups are provided:

- properties without matching Symfony Serializer metadata are excluded;
- properties with no matching group are excluded;

The context accepts either a single group string or an array of group strings:

```php
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;

new SymfonySerializerContext([
    AbstractNormalizer::GROUPS => ['public', 'details'],
]);
```

### Property-level contexts

Symfony property metadata can define normalization contexts per group. The strategy merges those property-level contexts with the global `SymfonySerializerContext` before projecting each property.

This matters for known normalizers whose output schema depends on context, such as date/time and UID normalizers.

### Skipping null values

`AbstractObjectNormalizer::SKIP_NULL_VALUES` affects requiredness for nullable properties:

```php
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;

new SymfonySerializerContext([
    AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
]);
```

When this option is `true`, nullable properties are marked as optional in the serialized schema, because Symfony can omit them from the payload when their value is `null`.

### Known normalizer formats

Some Symfony normalizers expose context keys that affect the serialized scalar format. The strategy reads the supported keys and adjusts the generated schema when possible:

| Context key | Effect |
|:---|:---|
| `DateTimeNormalizer::FORMAT_KEY` | Sets `format: date-time` for RFC3339-like formats, `format: date` for `Y-m-d`, or no schema format for custom formats. |
| `DateIntervalNormalizer::FORMAT_KEY` | Sets `format: duration` for Symfony's default ISO-8601-like duration format, or no schema format for custom formats. |
| `UidNormalizer::NORMALIZATION_FORMAT_KEY` | Sets `format: uuid` for canonical/RFC4122 UUID output; Base32/Base58 remain plain strings. |

Example:

```php
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\UidNormalizer;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;

new SymfonySerializerContext([
    DateTimeNormalizer::FORMAT_KEY => 'Y-m-d',
    UidNormalizer::NORMALIZATION_FORMAT_KEY => UidNormalizer::NORMALIZATION_FORMAT_BASE32,
]);
```

With this context, `DateTimeInterface` fields are projected as strings with `format: date`, while UUID fields normalized as Base32 are projected as plain strings without `format: uuid`.

### JSON encoder options

`JsonEncode::OPTIONS` is used when copying schema default values:

```php
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;

new SymfonySerializerContext([
    JsonEncode::OPTIONS => \JSON_FORCE_OBJECT,
]);
```

Defaults are round-tripped through `json_encode()` / `json_decode()` using these options so the JSON Schema default matches the JSON value produced by the configured Symfony Serializer. This is especially visible for empty arrays: with `JSON_FORCE_OBJECT`, an empty array default is represented as an empty JSON object.

## What it reads

- Symfony serializer class/property metadata.
- Optional `SymfonySerializerContext` (e.g. groups, skip-null policy).
- Known normalizer/discriminator behaviors.

## Projection behavior

- Map property names, taking into account the `SerializedName` attribute and name converters.
- Excludes properties based on the `Ignore` attribute and serialization groups.
- Rewrites types for known normalized values (date/time, UID, etc.).
- Takes into account discriminator class mapping.

## Supported mappings

| Serializer signal | Schema output | Notes |
| :--- | :--- | :--- |
| `SerializedName` | property key renamed in schema | Uses serialized name as output field name. |
| Serialization groups | fields included/excluded | Based on provided serializer context groups. |
| `DateTimeInterface` normalization | `type: string`, `format: date-time` (or `date` for known formats) | Replaces default `json_encode()` object shape. |
| `DateTimeZone` normalization | `type: string` | Matches `DateTimeZoneNormalizer` behavior. |
| `DateInterval` normalization | `type: string`, often `format: duration` | Custom formats may remain plain string. |
| Symfony UID normalization | `type: string`, `format: uuid` where applicable | Base32/Base58 remain plain string. |
| `TranslatableInterface` normalization | `type: string` | Locale changes value, not shape. |
| Data URI file normalization | `type: string`, pattern `^data:` | Matches `DataUriNormalizer`. |
| `ConstraintViolationListInterface` normalization | RFC 7807-like object shape | Requires relevant Symfony components installed. |
| `FlattenException` normalization | RFC 7807-like problem object shape | Requires relevant Symfony components installed. |
| Class discriminator mapping | discriminator field + `oneOf` concrete classes | Base/interface schemas resolve through configured map. |

## Limitations

- `JsonSerializableNormalizer` behavior is not reflected in the resulting JSON schema.
- Runtime-dependent serializer customizations outside known mappings are not handled, for example:
  - custom normalizers

## Example

Input DTO:

```php
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

final class Event
{
    #[Ignore]
    public string $name;
    
    #[SerializedName('created_at')]
    public \DateTimeInterface $createdAt;
}
```

Relevant schema output (excerpt):

```json
{
  "type": "object",
  "properties": {
    "created_at": {
      "type": "string",
      "format": "date-time"
    }
  }
}
```
