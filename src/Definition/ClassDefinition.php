<?php

namespace Zeusi\JsonSchemaGenerator\Definition;

/**
 * Root container representing the structure of a PHP class being transformed into a JSON Schema.
 */
class ClassDefinition implements ObjectShapeDefinitionInterface
{
    /**
     * @param string $className Fully qualified class name
     * @param array<string, PropertyDefinition> $properties Defined class-backed properties, indexed by their stable/internal name
     * @param array<string, FieldDefinitionInterface> $virtualFields Virtual fields introduced by enrichers, indexed by their stable/internal name
     * @param array<class-string> $concreteClasses
     */
    public function __construct(
        public readonly string $className,
        public array $properties = [],
        public array $virtualFields = [],
        public ?string $title = null,
        public ?string $description = null,
        public ?bool $additionalProperties = null,
        public array $concreteClasses = []
    ) {}

    /**
     * @return array<string, FieldDefinitionInterface>
     */
    public function getProperties(): array
    {
        // Class-backed properties win on key collisions by design.
        return $this->properties + $this->virtualFields;
    }

    public function addProperty(FieldDefinitionInterface $property): void
    {
        if ($property instanceof PropertyDefinition) {
            $this->properties[$property->getName()] = $property;
            return;
        }

        $this->virtualFields[$property->getName()] = $property;
    }

    public function removeProperty(string $propertyName): void
    {
        unset($this->properties[$propertyName], $this->virtualFields[$propertyName]);
    }

    public function getProperty(string $propertyName): ?FieldDefinitionInterface
    {
        return $this->properties[$propertyName] ?? $this->virtualFields[$propertyName] ?? null;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getAdditionalProperties(): ?bool
    {
        return $this->additionalProperties;
    }

    public function setAdditionalProperties(?bool $additionalProperties): void
    {
        $this->additionalProperties = $additionalProperties;
    }
}
