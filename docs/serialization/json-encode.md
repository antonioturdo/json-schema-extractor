# JsonEncodeSerializationStrategy

> Projects the extracted information into the result of the serialization performed with the native `json_encode` method.

## Dependencies

- None

## When to use it

Use this strategy when you use `json_encode` to serialize your objects, and the respective classes do not implement `JsonSerializable`.

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

## Projection behavior

- Public properties are mapped without modification.
- Map the `DateTimeInterface` type to an object with `date`, `timezone_type` and `timezone` properties.

## Limitations

- Does not support classes that implement `JsonSerializable`.

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
