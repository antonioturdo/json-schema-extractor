# PhpStanEnricher

> `PhpStanEnricher` reads PHPDoc through `phpstan/phpdoc-parser` and enriches the PHP model with precise PHPDoc type information.

## Dependencies

- `phpstan/phpdoc-parser`

## When to use it

Use this enricher when PHPDoc types are part of your source of truth and you want type-first extraction for shaped arrays, generics, unions, intersections, ranges, literals, and supported pseudo-types.

Compared to `PhpDocumentorEnricher`, this enricher is generally the better fit when PHPDoc type precision is more important than DocBlock text metadata. It still reads selected property text and tags, but it does not provide the same class-level summary/description handling as phpDocumentor.

## What it reads

- Property PHPDoc text.
- Property `@var` tags.
- Constructor `@param` tags for promoted properties.
- Property `@example` tags.
- Property `@deprecated` tags.

## What it writes

- Property description from PHPDoc free text.
- Property examples and deprecated marker.
- Property type from supported `@var` PHPDoc types and promoted-property constructor `@param` types.
- Type constraints from supported PHPDoc pseudo-types, literals, ranges, and shapes.

## Merge behavior

- Property types are applied only when compatible with the existing native/reflected type.
- Property annotations from PHPDoc can replace previous annotation values.
- Multiple `@var` tags on the same property are merged; compatible declarations share constraints, while incompatible declarations become a union type.

This means PHPDoc can refine a compatible native type, for example `array` plus `@var list<non-empty-string>`, but incompatible PHPDoc is not used to replace a stronger existing type.

## Supported mappings

| PHPDoc construct                                                     | Schema output                                       | Notes                                                            |
|:---------------------------------------------------------------------|:----------------------------------------------------|:-----------------------------------------------------------------|
| `string`, `int`, `float`, `bool`, `array`, `object`, `null`, `mixed` | Matching JSON Schema scalar/container type       | `mixed` remains unconstrained.                                   |
| `?T`, `T\|null`, `A\|B`                                              | Union type                                          | Nullable and compound types are preserved before schema mapping. |
| `A&B`                                                                | Intersection type                                   | Preserved before schema mapping.                                 |
| `T[]`, `array<T>`, `list<T>`, `iterable<T>`                          | Array with `items: <T>`                             | Nested generics are resolved recursively.                        |
| `array<string, T>`                                                   | Object with `additionalProperties: <T>`             | Dictionary-style arrays map to JSON objects.                     |
| `array{key: T, optional?: U}`                                        | Inline object shape                                 | Required keys come from non-optional shape items.                |
| `object{key: T}`                                                     | Inline object shape                                 | Treated like an anonymous object definition.                     |
| Nested shapes, for example `array<array{url: string}>`               | Nested arrays and inline objects                    | Supported recursively.                                           |
| `class-string`, `interface-string`, `literal-string`                 | String                                              | JSON only sees the serialized string value.                      |
| `array-key`, `key-of<T>`                                             | `string \| int`                                     | Conservative representation of key-like values.                  |
| `scalar`                                                             | `string \| int \| float \| bool`                    | Expanded to the supported scalar union.                          |
| `value-of<T>`                                                        | Mixed                                               | Currently represented conservatively.                            |
| `numeric-string`                                                     | String with numeric pattern                         | Pattern is conservative and JSON Schema-compatible.              |
| `lowercase-string`                                                   | String with lowercase pattern                       | Adds a pattern that rejects uppercase ASCII letters.             |
| `non-empty-string`                                                   | String with `minLength: 1`                          | Adds a non-empty string constraint.                              |
| `non-empty-lowercase-string`                                         | String with `minLength: 1` and lowercase pattern    | Combines both string constraints.                                |
| `positive-int`                                                       | Integer with `minimum: 1`                           | Maps to an inclusive lower bound.                                |
| `negative-int`                                                       | Integer with `maximum: -1`                          | Maps to an inclusive upper bound.                                |
| `int<min, max>`                                                      | Integer with `minimum` / `maximum`                  | Open-ended bounds are omitted.                                   |
| `int-mask<T>`, `int-mask-of<T>`                                      | Integer                                             | Represented as an integer without bitmask-specific constraints.  |
| `non-empty-array`, `non-empty-list`                                  | Array with `minItems: 1`                            | Can merge with a compatible array/list item type.                |
| String, integer, float, boolean, and null literals                   | `enum` or `null` constraint                         | Examples: `"draft"`, `123`, `true`, `false`, `null`.             |
| Class/interface/enum names                                           | Class-like or enum type                             | Known symbols are resolved against the PHPDoc context.           |
| `self`, `static`, `$this`, `parent`                                  | Class-like type                                     | Resolved relative to the declaring class.                        |
| `callable`, `resource`, `void`, `never`                              | Unknown type                                        | These do not have a precise JSON Schema representation here.     |

## Limitations

- Class PHPDoc summary and description are not read by this enricher.
- Property PHPDoc text is exposed as description; it is not split into title and description.

## Example

Input DTO:

```php
final class ProfilePayload
{
    /**
     * Public profile settings.
     *
     * @var array{email: non-empty-string, age?: int<18, 99>}
     * @example {"email":"ada@example.com","age":37}
     */
    public array $profile = [];
}
```

Relevant schema output:

```json
{
  "type": "object",
  "properties": {
    "profile": {
      "type": "object",
      "description": "Public profile settings.",
      "required": [
        "email"
      ],
      "properties": {
        "email": {
          "type": "string",
          "minLength": 1
        },
        "age": {
          "type": "integer",
          "minimum": 18,
          "maximum": 99
        }
      },
      "examples": [
        {
          "email": "ada@example.com",
          "age": 37
        }
      ]
    }
  }
}
```

## Notes

- This enricher is a good fit when PHPDoc type precision is more important than class-level DocBlock text metadata.
- If you use both `PhpStanEnricher` and `PhpDocumentorEnricher`, their order can matter when both write to the same metadata.
