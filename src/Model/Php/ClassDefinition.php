<?php

namespace Zeusi\JsonSchemaExtractor\Model\Php;

/**
 * Root container representing the structure of a PHP class being transformed into a JSON Schema.
 */
class ClassDefinition
{
    /**
     * @param string $className Fully qualified class name
     * @param array<string, PropertyDefinition> $properties Defined class-backed properties, indexed by their stable/internal name
     */
    public function __construct(
        private readonly string $className,
        private array $properties = [],
        private ?string $title = null,
        private ?string $description = null,
    ) {}

    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @return array<string, PropertyDefinition>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function addProperty(PropertyDefinition $property): void
    {
        $this->properties[$property->getName()] = $property;
    }

    public function getProperty(string $propertyName): ?PropertyDefinition
    {
        return $this->properties[$propertyName] ?? null;
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

}
