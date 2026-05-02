<?php

namespace Zeusi\JsonSchemaGenerator\Enricher;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Type;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;
use Zeusi\JsonSchemaGenerator\Definition\Type\ArrayType;
use Zeusi\JsonSchemaGenerator\Definition\Type\BuiltinType;
use Zeusi\JsonSchemaGenerator\Definition\Type\ClassLikeType;
use Zeusi\JsonSchemaGenerator\Definition\Type\EnumType;
use Zeusi\JsonSchemaGenerator\Definition\Type\MapType;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExpr;
use Zeusi\JsonSchemaGenerator\Definition\Type\TypeExprUtils;
use Zeusi\JsonSchemaGenerator\Definition\Type\UnionType;
use Zeusi\JsonSchemaGenerator\Definition\TypeAnnotations;

/**
 * Enriches properties by extracting metadata from PHPDoc blocks.
 * Specifically useful for reading generic array types (e.g. array<Tag>)
 * and extracting human-readable descriptions.
 */
class SymfonyPhpDocPropertyEnricher implements PropertyEnricherInterface
{
    private PhpDocExtractor $extractor;

    public function __construct()
    {
        $this->extractor = new PhpDocExtractor();
    }

    public function enrich(ClassDefinition $definition, GenerationContext $context): void
    {
        $className = $definition->className;

        if (!class_exists($className)) {
            return;
        }

        foreach ($definition->properties as $propertyName => $propertyDef) {
            // 1. Extract and append human description from docblocks
            $title = $this->extractor->getShortDescription($className, $propertyName);
            if ($title !== null && $title !== '') {
                $propertyDef->setTypeExpr(TypeExprUtils::decorateNonNullBranches($propertyDef->getTypeExpr(), function ($decorated) use ($title): void {
                    $decorated->annotations ??= new TypeAnnotations();
                    $decorated->annotations->title ??= $title;
                }));
            }

            $description = $this->extractor->getLongDescription($className, $propertyName);
            if ($description !== null && $description !== '') {
                $propertyDef->setTypeExpr(TypeExprUtils::decorateNonNullBranches($propertyDef->getTypeExpr(), function ($decorated) use ($description): void {
                    $decorated->annotations ??= new TypeAnnotations();
                    $decorated->annotations->description ??= $description;
                }));
            }

            // 2. Extract rich types (this is where `array<Tag>` gets resolved)
            $docTypes = $this->extractor->getTypes($className, $propertyName);
            if (!empty($docTypes)) {
                $propertyDef->setTypeExpr($this->translateSymfonyTypesToExpr($docTypes));
            }
        }
    }

    /**
     * @param Type[] $symfonyTypes
     */
    private function translateSymfonyTypesToExpr(array $symfonyTypes): TypeExpr
    {
        $types = [];
        foreach ($symfonyTypes as $symfonyType) {
            $builtinType = $symfonyType->getBuiltinType();
            $className = $symfonyType->getClassName();

            if ($symfonyType->isCollection()) {
                $keyTypes = $symfonyType->getCollectionKeyTypes();
                $valueTypes = $symfonyType->getCollectionValueTypes();
                $valueExpr = !empty($valueTypes) ? $this->translateSymfonyTypesToExpr($valueTypes) : new BuiltinType('mixed');

                $isStringKeyMap = false;
                if (!empty($keyTypes) && \count($keyTypes) === 1) {
                    $keyExpr = $this->translateSymfonyTypesToExpr([$keyTypes[0]]);
                    $isStringKeyMap = $keyExpr instanceof BuiltinType && $keyExpr->name === 'string';
                }

                $types[] = $isStringKeyMap ? new MapType($valueExpr) : new ArrayType($valueExpr);
                continue;
            }

            if ($className !== null && !class_exists($className) && !interface_exists($className) && !enum_exists($className)) {
                $className = null;
            }

            if ($builtinType === 'object' && $className !== null) {
                $types[] = enum_exists($className) ? new EnumType($className) : new ClassLikeType($className);
                continue;
            }

            $types[] = new BuiltinType($builtinType);
        }

        if (\count($types) === 1) {
            return $types[0];
        }

        return new UnionType($types);
    }

    // TypeExpr decorations are handled by {@see TypeExprUtils}.
}
