# SymfonyValidationEnricher

> `SymfonyValidationEnricher` reads Symfony Validator metadata and enriches the PHP model with supported validation constraints.

## Dependencies

- `symfony/validator`

Supported Symfony versions follow the constraints declared in `composer.json`.

## When to use it

Use this enricher when your DTO or domain objects are validated with Symfony Validator and you want generated schemas to reflect those validation rules.

This enricher is constraint-first: it is authoritative for supported Validator constraints, but it does not try to infer the original PHP type on its own. It works best together with a type-oriented enricher such as `PhpStanEnricher` or `PhpDocumentorEnricher`.

## What it reads

- Symfony Validator property metadata.
- Property constraints, including nested constraints inside `All` and `Collection`.

## What it writes

- Field requiredness.
- String format annotations.
- String constraints.
- Numeric constraints.
- Array item-count constraints.
- Enum constraints.
- Inline object shapes for `Collection` constraints.
- Nested item constraints for `All` constraints.

## Merge behavior

- `NotBlank` and `NotNull` mark the field as required.
- `Collection` creates an inline object shape and applies it only when compatible with the existing property type.
- Supported constraints can add annotations and constraints even when they make an existing type/schema visibly inconsistent.

This means constraint/type incoherences are not silently fixed. For example, if a field is typed as `int` but has `Email` or `Length` constraints, the generated schema can expose incompatible information until the model or constraints are corrected.

## Supported mappings

| Symfony constraint                                          | Schema output                                                                                                | Notes                                                            |
|:------------------------------------------------------------|:-------------------------------------------------------------------------------------------------------------|:-----------------------------------------------------------------|
| `NotBlank`, `NotNull`                                      | Adds field name to parent object `required` array                                                            | Requiredness is about field presence in the serialized payload.  |
| `Length`                                                    | `minLength`, `maxLength`                                                                                     | Applied as string-length constraints.                            |
| `Regex`                                                     | `pattern`                                                                                                    | Regex is propagated as schema pattern.                           |
| `Email`                                                     | `format: email`                                                                                              | Applied as JSON Schema string format annotation.                 |
| `Url`                                                       | `format: uri`                                                                                                | Applied as JSON Schema string format annotation.                 |
| `Uuid`                                                      | `format: uuid`                                                                                               | Applied as JSON Schema string format annotation.                 |
| `Ip`                                                        | `format: ipv4` or `format: ipv6`                                                                             | Depends on the Symfony constraint version option.                |
| `Hostname`                                                  | `format: hostname`                                                                                           | Applied as JSON Schema string format annotation.                 |
| `Range`                                                     | `minimum`, `maximum`                                                                                         | Numeric boundaries.                                              |
| `Positive`                                                  | `exclusiveMinimum: 0`                                                                                        | Strictly greater than zero.                                      |
| `PositiveOrZero`                                            | `minimum: 0`                                                                                                 | Greater than or equal to zero.                                   |
| `Negative`                                                  | `exclusiveMaximum: 0`                                                                                        | Strictly less than zero.                                         |
| `NegativeOrZero`                                            | `maximum: 0`                                                                                                 | Less than or equal to zero.                                      |
| `GreaterThan`                                               | `exclusiveMinimum`                                                                                           | Uses the constraint value.                                       |
| `GreaterThanOrEqual`                                        | `minimum`                                                                                                    | Uses the constraint value.                                       |
| `LessThan`                                                  | `exclusiveMaximum`                                                                                           | Uses the constraint value.                                       |
| `LessThanOrEqual`                                           | `maximum`                                                                                                    | Uses the constraint value.                                       |
| `DivisibleBy`                                               | `multipleOf`                                                                                                 | Maps to JSON Schema divisibility rule.                           |
| `Count`                                                     | `minItems`, `maxItems`                                                                                       | Applied as array item-count constraints.                         |
| `Choice`                                                    | `enum`                                                                                                       | Supported choices become enum values.                            |
| `All`                                                       | Applies supported nested mappings to array `items` type                                                      | Nested mapping runs on item branches.                            |
| `Collection`                                                | Replaces/creates field type as inline object shape with `properties`, `required`, and `additionalProperties` | Handles `Required`/`Optional` keys and nested field constraints. |

## Limitations

- Not all Symfony Validator constraints are currently mapped.
- `Collection` fields rely on Symfony's normalized `Required` / `Optional` wrappers.
- Constraint/type incoherences are exposed in the schema rather than automatically corrected.

## Example

Input DTO:

```php
use Symfony\Component\Validator\Constraints as Assert;

final class SignupPayload
{
    #[Assert\Length(min: 3, max: 10)]
    public string $username = '';

    #[Assert\Collection(
        fields: [
            'email' => new Assert\Required([
                new Assert\NotBlank(),
                new Assert\Email(),
            ]),
            'age' => new Assert\Optional([
                new Assert\Range(min: 18, max: 99),
            ]),
        ],
        allowExtraFields: false,
    )]
    public array $profile = [];
}
```

Pipeline:

```php
[
    new PhpStanEnricher(),
    new SymfonyValidationEnricher($validatorMetadataFactory),
]
```

Relevant schema output:

```json
{
  "type": "object",
  "properties": {
    "username": {
      "type": "string",
      "minLength": 3,
      "maxLength": 10
    },
    "profile": {
      "type": "object",
      "additionalProperties": false,
      "required": [
        "email"
      ],
      "properties": {
        "email": {
          "type": "string",
          "format": "email"
        },
        "age": {
          "type": "number",
          "minimum": 18,
          "maximum": 99
        }
      }
    }
  }
}
```

## Notes

- This enricher is a good fit when Symfony Validator constraints are part of the payload contract you want reflected in the schema.
- If you use it with a PHPDoc enricher, the usual order is type-oriented enricher first, validation enricher after.
