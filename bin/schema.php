#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Zeusi\JsonSchemaGenerator\Context\GenerationContext;
use Zeusi\JsonSchemaGenerator\Context\SymfonySerializerContext;
use Zeusi\JsonSchemaGenerator\Discoverer\ReflectionPropertyDiscoverer;
use Zeusi\JsonSchemaGenerator\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\SerializerPropertyEnricher;
use Zeusi\JsonSchemaGenerator\Enricher\SymfonyValidationEnricher;
use Zeusi\JsonSchemaGenerator\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaGenerator\SchemaGenerator;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\BasicObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\CircularObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\PhpDocObject;
use Zeusi\JsonSchemaGenerator\Tests\Fixtures\ValidatedObject;

// Setup Serializer Metadata
$classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
$nameConverter = new MetadataAwareNameConverter($classMetadataFactory);

// Setup Symfony Validator Metadata
$metadataFactory = new Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory(
    new Symfony\Component\Validator\Mapping\Loader\AttributeLoader()
);

$generator = new SchemaGenerator(
    new ReflectionPropertyDiscoverer(setTitleFromClassName: true),
    [
        new SerializerPropertyEnricher($classMetadataFactory, $nameConverter),
        // new PhpDocumentorEnricher(),
        new SymfonyValidationEnricher($metadataFactory),
        new PhpStanEnricher(),
    ],
    new StandardSchemaMapper()
);

// Classes to analyze
/** @var array<class-string> $classes */
$classes = isset($argv[1])
    ? [$argv[1]]
    : [
        BasicObject::class,
        PhpDocObject::class,
        CircularObject::class,
        ValidatedObject::class,
    ];

foreach ($classes as $class) {
    echo "\n\e[1;36m══════════════════════════════════════════════════\e[0m\n";
    echo "\e[1;36m  Schema: {$class}\e[0m\n";
    echo "\e[1;36m══════════════════════════════════════════════════\n";

    try {
        $context = (new GenerationContext())
            ->with(new SymfonySerializerContext());

        $schema = $generator->generate($class, $context);
        echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "\e[1;31mERRORE: {$e->getMessage()}\e[0m\n";
    }
}

echo "\n\e[1;32mDone.\e[0m\n";
