# SymfonySerializerStrategy

> Aligns extracted schema with actual Symfony Serializer output.

## Dependencies

- `symfony/serializer`

Some known-normalizer mappings also depend on the Symfony package that defines the normalized type:

- `symfony/uid` for Symfony UID values.
- `symfony/validator` for `ConstraintViolationListInterface`.
- `symfony/form` for `FormInterface`.
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

Known normalizer mappings assume a standard Symfony Serializer setup where the corresponding normalizers are enabled. The strategy does not inspect the actual `Serializer` service or its configured normalizer chain, so removed, reordered, or custom normalizers may produce runtime payloads that differ from the generated schema.

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

### Ignored attributes

`AbstractNormalizer::IGNORED_ATTRIBUTES` excludes properties at runtime by their original PHP attribute names:

```php
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;

new SymfonySerializerContext([
    AbstractNormalizer::IGNORED_ATTRIBUTES => ['internalNotes'],
]);
```

The strategy applies the same exclusion before serialized names and name converters are applied.

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

### `JsonSerializable`

For classes that implement `JsonSerializable`, the strategy follows Symfony's `JsonSerializableNormalizer` behavior when the discovered `jsonSerialize()` return type can be projected to a precise payload type.

This support is metadata-driven: `jsonSerialize()` is not executed and its method body is not analyzed. Use PHPDoc return metadata, such as `@return array{id: int, name: string}`, together with a PHPDoc enricher to describe object-like arrays.

When usable `jsonSerialize()` return metadata is available, it is treated as the root serialized payload and the normal property projection is skipped. If no usable return metadata is available, the strategy throws a `LogicException` because it cannot infer the actual `JsonSerializable` payload without executing the method. Bare `array`, `iterable`, `object`, and `mixed` return types are intentionally treated as too vague.

### Custom serialization behavior

Custom Symfony normalizers, `AbstractNormalizer::CALLBACKS`, and similar serializer customizations can contain arbitrary application logic, so `SymfonySerializerStrategy` does not try to inspect or execute them.
When application-specific serialization changes the payload shape, create your own `SerializationStrategyInterface` implementation and delegate to `SymfonySerializerStrategy` for the standard Symfony behavior.

For example, an application can serialize a `Money` value object as a string and serialize an `owner` object property as its identifier:

```php
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedObjectDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPayloadDefinition;
use Zeusi\JsonSchemaExtractor\Model\Serialized\SerializedPropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\SerializedObjectType;
use Zeusi\JsonSchemaExtractor\Serialization\SerializationStrategyInterface;
use Zeusi\JsonSchemaExtractor\Serialization\State\ProjectionState;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;

final class AppSerializerStrategy implements SerializationStrategyInterface
{
    public function __construct(
        private SymfonySerializerStrategy $inner,
    ) {}

    public function initialState(ExtractionContext $context): ProjectionState
    {
        return $this->inner->initialState($context);
    }

    public function project(ClassDefinition $definition, ExtractionContext $context, ProjectionState $state): SerializedPayloadDefinition
    {
        $payload = $this->inner->project($definition, $context, $state);
        if (!$payload->type instanceof SerializedObjectType) {
            return $payload;
        }

        $properties = [];
        foreach ($payload->type->shape->properties as $name => $property) {
            $type = $property->type;
            if ($type instanceof ClassLikeType && $type->name === Money::class) {
                $type = new BuiltinType('string');
            }
            if ($name === 'owner') {
                $type = new BuiltinType('string');
            }

            $properties[$name] = new SerializedPropertyDefinition(
                name: $property->name,
                required: $property->required,
                type: $type,
            );
        }

        return new SerializedPayloadDefinition(
            new SerializedObjectType(new SerializedObjectDefinition(
                name: $payload->type->shape->name,
                properties: $properties,
                title: $payload->type->shape->title,
                description: $payload->type->shape->description,
                additionalProperties: $payload->type->shape->additionalProperties,
                concreteClasses: $payload->type->shape->concreteClasses,
            )),
            $payload->inlineOnly,
        );
    }
}
```

This keeps custom behavior explicit while still reusing Symfony metadata support for groups, serialized names, discriminators, and built-in normalizer mappings. For nested values, repeat the same idea recursively or create a small project-specific helper.

## What it reads

- Symfony serializer class/property metadata.
- Optional `SymfonySerializerContext` (e.g. groups, ignored attributes, skip-null policy).
- `JsonSerializable::jsonSerialize()` return metadata when available.
- Known normalizer/discriminator behaviors.

## Projection behavior

- Map property names, taking into account the `SerializedName` attribute and name converters.
- Excludes properties based on ignored attributes, the `Ignore` attribute, and serialization groups.
- Rewrites types for known normalized values (date/time, UID, etc.).
- Takes into account discriminator class mapping.

## Supported mappings

| Serializer signal | Schema output | Notes |
| :--- | :--- | :--- |
| `SerializedName` | property key renamed in schema | Uses serialized name as output field name. |
| Serialization groups | fields included/excluded | Based on provided serializer context groups. |
| `AbstractNormalizer::IGNORED_ATTRIBUTES` | fields excluded | Uses original PHP attribute names from the serializer context. |
| `AbstractNormalizer::ATTRIBUTES` | per-property nested views | Filters which attributes are serialized and projects each nested class under its narrowed view, emitted inline as a bespoke shape. Uses original PHP attribute names. |
| `DateTimeInterface` normalization | `type: string`, `format: date-time` (or `date` for known formats) | Replaces default `json_encode()` object shape. |
| `DateTimeZone` normalization | `type: string` | Matches `DateTimeZoneNormalizer` behavior. |
| `DateInterval` normalization | `type: string`, often `format: duration` | Custom formats may remain plain string. |
| Symfony UID normalization | `type: string`, `format: uuid` where applicable | Base32/Base58 remain plain string. |
| `BcMath\Number` / `GMP` normalization | `type: string` | Matches Symfony's `NumberNormalizer` behavior. |
| `TranslatableInterface` normalization | `type: string` | Locale changes value, not shape. |
| Data URI file normalization | `type: string`, pattern `^data:` | Matches `DataUriNormalizer`. |
| `JsonSerializable` normalization | payload declared by `jsonSerialize()` return metadata | Supports precise object, scalar, list, and dictionary payloads. |
| `ConstraintViolationListInterface` normalization | RFC 7807-like object shape | Requires relevant Symfony components installed. |
| `FormInterface` normalization | form error object shape with `title`, `type`, `code`, `errors`, and optional `children` | Matches `FormErrorNormalizer` for invalid submitted forms. |
| `FlattenException` normalization | RFC 7807-like problem object shape | Requires relevant Symfony components installed. |
| Class discriminator mapping | discriminator field + `oneOf` concrete classes | Base/interface schemas resolve through configured map. |

## Limitations

- Runtime-dependent serializer customizations outside known mappings are not handled automatically.
- Runtime state-dependent options are not modeled automatically:
  - `AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS` can preserve empty object-like values as JSON objects, but it does not prove that the same property cannot serialize as an array or dictionary when populated.
  - `AbstractObjectNormalizer::SKIP_UNINITIALIZED_VALUES` can omit uninitialized properties, but the strategy does not inspect constructor/body assignments or object instance state.
- `#[MaxDepth]` / `AbstractObjectNormalizer::ENABLE_MAX_DEPTH` are not modeled as a tightened schema, and do not need to be: recursive references are already broken with a `$ref` to the class definition, and a depth-bounded payload is a valid instance of that (looser) recursive schema, since a recursive attribute that the serializer omits at the depth limit is optional in the schema. The generated schema therefore describes the recursive structure soundly; it just does not forbid nesting beyond the runtime depth limit. This reasoning assumes the recursive attribute is optional; a required, non-nullable self-reference is a separate, degenerate modeling case.
- Handlers that replace nested objects with arbitrary application-specific values are not modeled automatically:
  - `AbstractObjectNormalizer::MAX_DEPTH_HANDLER` and `AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER` produce opaque, code-defined output; model these cases with a custom serialization strategy when needed.

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
