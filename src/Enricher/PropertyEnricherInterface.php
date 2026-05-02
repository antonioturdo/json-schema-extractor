<?php

namespace Zeusi\JsonSchemaGenerator\Enricher;

use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Definition\ClassDefinition;

/**
 * Manipolatore centrale della pipeline. Modifica la ClassDefinition
 * arricchendola o impoverendola di proprietà o di tipi.
 */
interface PropertyEnricherInterface
{
    /**
     * Interrompere la pipeline ritornando false non è ancora supportato in questa iterazione primaria,
     * la manipolazione avviene per mutazione dell'oggetto $definition in-place.
     */
    public function enrich(ClassDefinition $definition, GenerationContext $context): void;
}
