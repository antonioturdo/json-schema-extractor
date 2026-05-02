<?php

namespace Zeusi\JsonSchemaExtractor\Discoverer;

use Zeusi\JsonSchemaExtractor\Model\Php\ClassDefinition;
use Zeusi\JsonSchemaExtractor\Model\Php\PropertyDefinition;
use Zeusi\JsonSchemaExtractor\Model\Type\BuiltinType;
use Zeusi\JsonSchemaExtractor\Model\Type\ClassLikeType;
use Zeusi\JsonSchemaExtractor\Model\Type\DecoratedType;
use Zeusi\JsonSchemaExtractor\Model\Type\EnumType;
use Zeusi\JsonSchemaExtractor\Model\Type\IntersectionType;
use Zeusi\JsonSchemaExtractor\Model\Type\Type;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeAnnotations;
use Zeusi\JsonSchemaExtractor\Model\Type\TypeConstraints;
use Zeusi\JsonSchemaExtractor\Model\Type\UnionType;

/**
 * Parses basic properties off a class using native PHP Reflection API.
 * Designed to have no external dependencies, running pure PHP 8.1+ Reflection logic.
 */
class ReflectionDiscoverer implements DiscovererInterface
{
    public function __construct(
        private readonly bool $setTitleFromClassName = true
    ) {}

    /**
     * Extracts all accessible properties and generates the initial base `ClassDefinition`.
     *
     * @param class-string $className
     */
    public function discover(string $className): ClassDefinition
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException(\sprintf('Class "%s" does not exist.', $className));
        }

        $definition = new ClassDefinition($className);
        $reflection = new \ReflectionClass($className);

        if ($this->setTitleFromClassName) {
            $definition->setTitle($reflection->getShortName());
        }

        // Pre-extract promoted properties default values from constructor
        $promotedDefaults = [];
        $constructor = $reflection->getConstructor();
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->isPromoted() && $param->isDefaultValueAvailable()) {
                    $promotedDefaults[$param->getName()] = $param->getDefaultValue();
                }
            }
        }

        // Iterate over all instance (non-static) properties
        foreach ($reflection->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }

            $propertyName = $reflectionProperty->getName();
            $propertyDefinition = new PropertyDefinition(propertyName: $propertyName);

            // Extract PHP 8 types directly using reflection
            $reflectionType = $reflectionProperty->getType();
            if ($reflectionType !== null) {
                $propertyDefinition->setType($this->mapReflectionTypeToExpr($reflectionType, $reflection));
            }

            // Extract default values (handles both standard and promoted properties)
            $defaultValue = null;
            $hasDefault = false;

            if (\array_key_exists($propertyName, $promotedDefaults)) {
                $defaultValue = $promotedDefaults[$propertyName];
                $hasDefault = true;
            } elseif ($reflectionProperty->hasDefaultValue()) {
                $defaultValue = $reflectionProperty->getDefaultValue();
                $hasDefault = true;
            }

            if ($hasDefault) {
                if ($defaultValue instanceof \UnitEnum) {
                    $default = $defaultValue instanceof \BackedEnum ? $defaultValue->value : $defaultValue->name;
                    $propertyDefinition->setType($this->applyDefaultAnnotationToExpr($propertyDefinition->getType(), $default));
                } elseif ($this->isJsonCompatibleDefaultValue($defaultValue)) {
                    $propertyDefinition->setType($this->applyDefaultAnnotationToExpr($propertyDefinition->getType(), $defaultValue));
                }
            }

            $definition->addProperty($propertyDefinition);
        }

        return $definition;
    }

    /**
     * Parses a generic \ReflectionType into our internal Type tree.
     *
     * @param \ReflectionClass<object> $declaringClass
     */
    private function mapReflectionTypeToExpr(\ReflectionType $reflectionType, \ReflectionClass $declaringClass): Type
    {
        if ($reflectionType instanceof \ReflectionUnionType) {
            $types = [];
            foreach ($reflectionType->getTypes() as $subType) {
                if ($subType instanceof \ReflectionNamedType) {
                    $types[] = $this->mapNamedTypeToExpr($subType, $declaringClass);
                }
            }
            return new UnionType($types);
        }

        if ($reflectionType instanceof \ReflectionIntersectionType) {
            $types = [];
            foreach ($reflectionType->getTypes() as $subType) {
                if ($subType instanceof \ReflectionNamedType) {
                    $types[] = $this->mapNamedTypeToExpr($subType, $declaringClass);
                }
            }
            return new IntersectionType($types);
        }

        if ($reflectionType instanceof \ReflectionNamedType) {
            $base = $this->mapNamedTypeToExpr($reflectionType, $declaringClass);

            if ($reflectionType->allowsNull() && $reflectionType->getName() !== 'null') {
                return new UnionType([$base, new BuiltinType('null')]);
            }

            return $base;
        }

        throw new \LogicException(\sprintf('Unsupported reflection type "%s".', $reflectionType::class));
    }

    /**
     * Maps a \ReflectionNamedType into our internal representation.
     *
     * @param \ReflectionClass<object> $declaringClass
     */
    private function mapNamedTypeToExpr(\ReflectionNamedType $namedType, \ReflectionClass $declaringClass): Type
    {
        $name = $namedType->getName();
        $isBuiltin = $namedType->isBuiltin();

        if ($isBuiltin) {
            return new BuiltinType($name);
        }

        if ($name === 'self' || $name === 'static') {
            $name = $declaringClass->getName();
        } elseif ($name === 'parent') {
            $parent = $declaringClass->getParentClass();
            if ($parent !== false) {
                $name = $parent->getName();
            }
        }

        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            return new BuiltinType('object');
        }

        if (enum_exists($name)) {
            return new EnumType($name);
        }

        return new ClassLikeType($name);
    }

    private function applyDefaultAnnotationToExpr(?Type $expr, mixed $default): ?Type
    {
        if ($expr === null) {
            return null;
        }

        // Attach default to the whole expr (annotation keyword).
        if ($expr instanceof DecoratedType) {
            $expr->annotations ??= new TypeAnnotations();
            $expr->annotations->default = $default;
            return $expr;
        }

        return new DecoratedType($expr, new TypeConstraints(), new TypeAnnotations(default: $default));
    }

    private function isJsonCompatibleDefaultValue(mixed $value): bool
    {
        if ($value === null || \is_scalar($value)) {
            return true;
        }

        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$this->isJsonCompatibleDefaultValue($item)) {
                return false;
            }
        }

        return true;
    }
}
