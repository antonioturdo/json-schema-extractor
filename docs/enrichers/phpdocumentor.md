# PhpDocumentorEnricher

> `PhpDocumentorEnricher` reads PHPDoc blocks through `phpdocumentor/reflection-docblock` and enriches the PHP model with DocBlock text metadata and supported PHPDoc types.

## Dependencies

- `phpdocumentor/reflection-docblock:^5.3 || ^6.0`

The enricher supports both phpDocumentor 5 and 6. Internally, version-specific type mappers handle API differences between `phpdocumentor/type-resolver` versions, while the public enricher API stays the same.

## When to use it

Use this enricher when DocBlocks are part of your source of truth and you want schema metadata from summaries, descriptions, examples, deprecation tags, and PHPDoc types.

Compared to `PhpStanEnricher`, this enricher is especially useful when DocBlock text metadata matters. `PhpStanEnricher` is the type-first option: it is generally the better fit when PHPDoc type precision is more important than DocBlock text metadata. For the mappings listed below, both enrichers cover many of the same constructs.

## What it reads

- Class PHPDoc summary and description.
- Property PHPDoc summary and description.
- Property `@var` tags.
- Constructor `@param` tags for promoted properties.
- Property `@example` tags.
- Property `@deprecated` tags.

## What it writes

- Class title and description.
- Property title, description, examples, and deprecated marker.
- Property type from supported `@var` PHPDoc types and promoted-property constructor `@param` types.
- Type constraints from supported PHPDoc pseudo-types, literals, ranges, and shapes.

## Merge behavior

- Property types are applied only when compatible with the existing native/reflected type.
- Property and class annotations from PHPDoc can replace previous annotation values.
- Multiple `@var` tags on the same property are combined as a union type.

This means PHPDoc can refine a compatible native type, for example `array` plus `@var array{theme: string}`, but incompatible PHPDoc is not used to replace a stronger existing type.

## Supported mappings

| PHPDoc construct                                                 | Schema output                                       | Notes                                                            |
|:-----------------------------------------------------------------|:----------------------------------------------------|:-----------------------------------------------------------------|
| `string`, `int`, `float`, `bool`, `null`, `mixed`                | Matching JSON Schema scalar type                    | `mixed` remains unconstrained.                                   |
| `?T`, `T\|null`, `A\|B`                                          | Union type                                          | Nullable and compound types are preserved before schema mapping. |
| `T[]`, `array<T>`, `list<T>`, `iterable<T>`                      | Array with `items: <T>`                             | List-like collections map to JSON arrays.                        |
| `array<string, T>`                                               | Object with `additionalProperties: <T>`             | Dictionary-style arrays map to JSON objects.                     |
| `array{key: T, optional?: U}`                                    | Inline object shape                                 | Required keys come from non-optional shape items.                |
| `object{key: T}`                                                 | Inline object shape                                 | Treated like an anonymous object definition.                     |
| Nested shapes, for example `array<array{url: string}>`           | Nested arrays and inline objects                    | Supported recursively.                                           |
| `class-string`, `interface-string`                               | String                                              | JSON only sees the serialized class/interface name.              |
| `array-key`                                                      | `string \| int`                                     | Represents valid PHP array keys.                                 |
| `scalar`                                                         | `string \| int \| float \| bool`                    | Expanded to the supported scalar union.                          |
| `numeric`                                                        | `string \| int \| float` with numeric string pattern | Numeric string branch carries a pattern.                         |
| `numeric-string`                                                 | String with numeric pattern                         | Pattern is conservative and JSON Schema-compatible.              |
| `non-empty-string`                                               | String with `minLength: 1`                          | Adds a non-empty string constraint.                              |
| `non-empty-lowercase-string`                                     | String with `minLength: 1` and lowercase pattern    | Lowercase-only `lowercase-string` is currently treated as plain `string`. |
| `positive-int`                                                   | Integer with `minimum: 1`                           | Maps to an inclusive lower bound.                                |
| `negative-int`                                                   | Integer with `maximum: -1`                          | Maps to an inclusive upper bound.                                |
| `int<min, max>`                                                  | Integer with `minimum` / `maximum`                  | Open-ended bounds are omitted.                                   |
| `non-empty-array`, `non-empty-list`                              | Array with `minItems: 1`                            | Preserves the mapped item type when available.                   |
| String, integer, float, and boolean literals                     | `enum` constraint                                   | Examples: `"draft"`, `123`, `true`, `false`.                     |
| Class/interface/enum names                                       | Class-like or enum type                             | Known symbols are resolved against the PHPDoc context.           |
| `callable`, `resource`, `void`, `never`                          | Unknown type                                        | These do not have a precise JSON Schema representation here.     |

These mappings are supported with both phpDocumentor 5 and 6. The enricher uses version-specific mappers to normalize differences in phpDocumentor's internal type model.

## Example

Input DTO:

```php
/**
 * Public profile payload.
 *
 * Returned by the public profile endpoint.
 */
final class UserProfile
{
    /**
     * User preferences.
     *
     * @var array{theme: non-empty-string, notifications: bool, locale?: string}
     * @example {"theme":"dark","notifications":true}
     */
    public array $preferences = [];
}
```

Relevant schema output:

```json
{
  "type": "object",
  "title": "Public profile payload.",
  "description": "Returned by the public profile endpoint.",
  "properties": {
    "preferences": {
      "type": "object",
      "title": "User preferences.",
      "properties": {
        "theme": {
          "type": "string",
          "minLength": 1
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
      ],
      "examples": [
        {
          "theme": "dark",
          "notifications": true
        }
      ]
    }
  }
}
```

## Notes

- This enricher is a good fit when PHPDoc documentation quality is important for the generated schema.
- If you use both `PhpDocumentorEnricher` and `PhpStanEnricher`, their order can matter when both write to the same metadata.
