<?php

namespace Zeusi\JsonSchemaGenerator\JsonSchema;

/**
 * Representation of a JSON Schema node.
 */
class Schema implements \JsonSerializable
{
    /** @var string|array<string>|null */
    private string|array|null $type = null;
    private ?string $ref = null;
    private ?string $format = null;
    private ?string $title = null;
    private ?string $description = null;

    /** @var array<string, Schema>|null */
    private ?array $properties = null;

    private ?Schema $items = null;

    /** @var array<Schema>|null */
    private ?array $oneOf = null;

    /** @var array<Schema>|null */
    private ?array $anyOf = null;

    /** @var array<Schema>|null */
    private ?array $allOf = null;

    /** @var array<string>|null */
    private ?array $required = null;

    /** @var array<mixed>|null */
    private ?array $enum = null;

    // String constraints
    private ?int $minLength = null;
    private ?int $maxLength = null;
    private ?string $pattern = null;

    // Array constraints
    private ?int $minItems = null;
    private ?int $maxItems = null;

    // Numeric constraints
    private int|float|null $minimum = null;
    private int|float|null $maximum = null;
    private int|float|null $exclusiveMinimum = null;
    private int|float|null $exclusiveMaximum = null;
    private int|float|null $multipleOf = null;

    // Additional Metadata
    private bool $deprecated = false;
    /** @var array<mixed>|null */
    private ?array $examples = null;
    private mixed $default = null;

    private bool|Schema|null $additionalProperties = null;

    /** @var array<string, Schema>|null */
    private ?array $definitions = null;

    // Fluent Setters
    public function setType(?SchemaType $type): self
    {
        $this->type = $type?->value;
        return $this;
    }

    /**
     * Sets a union of types using the JSON Schema "type" array form.
     *
     * @param array<SchemaType> $types
     */
    public function setTypeUnion(array $types): self
    {
        $this->type = array_values(array_unique(array_map(static fn(SchemaType $t) => $t->value, $types)));
        return $this;
    }

    public function setRef(?string $ref): self
    {
        $this->ref = $ref;
        return $this;
    }

    public function setFormat(?string $format): self
    {
        $this->format = $format;
        return $this;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function addProperty(string $name, Schema $schema, bool $required = false): self
    {
        $this->properties[$name] = $schema;
        if ($required) {
            $this->required[] = $name;
        }
        return $this;
    }

    public function setItems(?Schema $items): self
    {
        $this->items = $items;
        return $this;
    }

    /**
     * @param array<self>|null $schemas
     */
    public function setOneOf(?array $schemas): self
    {
        $this->oneOf = $schemas;
        return $this;
    }

    /**
     * @param array<self>|null $schemas
     */
    public function setAnyOf(?array $schemas): self
    {
        $this->anyOf = $schemas;
        return $this;
    }

    /**
     * @param array<self>|null $schemas
     */
    public function setAllOf(?array $schemas): self
    {
        $this->allOf = $schemas;
        return $this;
    }

    /**
     * @param array<mixed>|null $enum
     */
    public function setEnum(?array $enum): self
    {
        $this->enum = $enum;
        return $this;
    }

    public function setAdditionalProperties(bool|Schema|null $additionalProperties): self
    {
        $this->additionalProperties = $additionalProperties;
        return $this;
    }

    /**
     * @param array<string, Schema>|null $definitions
     */
    public function setDefinitions(?array $definitions): self
    {
        $this->definitions = $definitions;
        return $this;
    }

    // --- String constraints ---

    public function setMinLength(?int $minLength): self
    {
        $this->minLength = $minLength;
        return $this;
    }

    public function setMaxLength(?int $maxLength): self
    {
        $this->maxLength = $maxLength;
        return $this;
    }

    public function setPattern(?string $pattern): self
    {
        $this->pattern = $pattern;
        return $this;
    }

    // --- Additional Metadata ---

    public function setDeprecated(bool $deprecated): self
    {
        $this->deprecated = $deprecated;
        return $this;
    }

    /**
     * @param array<mixed>|null $examples
     */
    public function setExamples(?array $examples): self
    {
        $this->examples = $examples;
        return $this;
    }

    public function setDefault(mixed $default): self
    {
        $this->default = $default;
        return $this;
    }

    // --- Array constraints ---

    public function setMinItems(?int $minItems): self
    {
        $this->minItems = $minItems;
        return $this;
    }

    public function setMaxItems(?int $maxItems): self
    {
        $this->maxItems = $maxItems;
        return $this;
    }

    // --- Numeric constraints ---

    public function setMinimum(int|float|null $minimum): self
    {
        $this->minimum = $minimum;
        return $this;
    }

    public function setMaximum(int|float|null $maximum): self
    {
        $this->maximum = $maximum;
        return $this;
    }

    public function setExclusiveMinimum(int|float|null $exclusiveMinimum): self
    {
        $this->exclusiveMinimum = $exclusiveMinimum;
        return $this;
    }

    public function setExclusiveMaximum(int|float|null $exclusiveMaximum): self
    {
        $this->exclusiveMaximum = $exclusiveMaximum;
        return $this;
    }

    public function setMultipleOf(int|float|null $multipleOf): self
    {
        $this->multipleOf = $multipleOf;
        return $this;
    }

    /**
     * @return array<string, mixed>|object
     */
    public function jsonSerialize(): array|object
    {
        if (null !== $this->ref) {
            return ['$ref' => $this->ref];
        }

        $data = [
            'type'                 => $this->type,
            'format'               => $this->format,
            'title'                => $this->title,
            'description'          => $this->description,
            'properties'           => $this->properties ? array_map(fn(Schema $s) => $s->jsonSerialize(), $this->properties) : null,
            'items'                => $this->items ? $this->items->jsonSerialize() : null,
            'oneOf'                => $this->oneOf ? array_map(fn(Schema $s) => $s->jsonSerialize(), $this->oneOf) : null,
            'anyOf'                => $this->anyOf ? array_map(fn(Schema $s) => $s->jsonSerialize(), $this->anyOf) : null,
            'allOf'                => $this->allOf ? array_map(fn(Schema $s) => $s->jsonSerialize(), $this->allOf) : null,
            'required'             => $this->required,
            'enum'                 => $this->enum,
            'minLength'            => $this->minLength,
            'maxLength'            => $this->maxLength,
            'minItems'             => $this->minItems,
            'maxItems'             => $this->maxItems,
            'minimum'              => $this->minimum,
            'maximum'              => $this->maximum,
            'exclusiveMinimum'     => $this->exclusiveMinimum,
            'exclusiveMaximum'     => $this->exclusiveMaximum,
            'multipleOf'           => $this->multipleOf,
            'pattern'              => $this->pattern,
            'deprecated'           => $this->deprecated ?: null,
            'examples'             => $this->examples,
            'default'              => $this->default,
            'additionalProperties' => $this->additionalProperties instanceof self ? $this->additionalProperties->jsonSerialize() : $this->additionalProperties,
            'definitions'          => $this->definitions ? array_map(fn(Schema $s) => $s->jsonSerialize(), $this->definitions) : null,
        ];

        // remove null values
        $filtered = array_filter($data, static fn($v) => null !== $v);

        if (empty($filtered)) {
            return (object) []; // MUST return empty object {} when json encoded
        }

        return $filtered;
    }
}
