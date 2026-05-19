# JsonEncodeSerializationStrategy

> Projects the extracted information into the result of the serialization performed with the native `json_encode` method.

## Dependencies

- None

## When to use it

Use this strategy when your runtime payload is produced with PHP's native `json_encode()`.

Classes that implement `JsonSerializable` are supported when `jsonSerialize()` has discovered return type metadata precise enough to describe the payload. This may be an object shape, a scalar, a list, or a dictionary type. In practice, object-like arrays usually need PHPDoc such as `@return array{id: int, name: string}` and a PHPDoc enricher before serialization projection.

## Context

When using `SchemaExtractor` you can pass a context to specify the `flags` argument used by `json_encode()`:

```php

use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\JsonEncodeContext;

$context = new ExtractionContext();

$context = $context->with(new JsonEncodeContext(\JSON_FORCE_OBJECT));
```

This can be useful because JSON encoding flags can also affect the JSON Schema, for example when an empty array is used as a property default.

## What it reads

- Native types to locate properties that implement `\DateTimeInterface`.
- `JsonSerializable::jsonSerialize()` return metadata when available.

## Projection behavior

- Public properties are mapped without modification.
- Maps `DateTimeInterface` to the object shape produced by PHP's native JSON serialization (`date`, `timezone_type`, `timezone`).
- Uses the documented `jsonSerialize()` return type as the root payload for `JsonSerializable` classes.

## Limitations

- `JsonSerializable` support is metadata-driven: the method body is never executed or analyzed.
- If `jsonSerialize()` has no usable return metadata, the strategy throws a `LogicException` because it cannot infer the actual payload without executing the method. Bare `array`, `iterable`, `object`, and `mixed` return types are intentionally treated as too vague.

## Example

Input DTO:

```php
final class Event
{
    public string $name;
    public \DateTimeInterface $createdAt;
}
```

Relevant schema output (excerpt):

```json
{
  "type": "object",
  "properties": {
    "name": {
      "type": "string"
    },
    "createdAt": {
      "type": "object",
      "properties": {
        "date": {
          "type": "string",
          "pattern": "^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\.\\d{6}$"
        },
        "timezone_type": {
          "type": "integer",
          "enum": [
            1,
            2,
            3
          ]
        },
        "timezone": {
          "type": "string"
        }
      },
      "required": [
        "date",
        "timezone_type",
        "timezone"
      ],
      "additionalProperties": false
    }
  }
}
```
