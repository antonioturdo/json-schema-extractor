#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Zeusi\JsonSchemaExtractor\Context\ExtractionContext;
use Zeusi\JsonSchemaExtractor\Context\SymfonySerializerContext;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpDocumentorEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Enricher\SymfonyValidationEnricher;
use Zeusi\JsonSchemaExtractor\Mapper\StandardSchemaMapper;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\SymfonySerializerStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\BasicObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\CircularObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\PhpDocObject;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\ValidatedObject;

// Setup Serializer Metadata
$classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
$nameConverter = new MetadataAwareNameConverter($classMetadataFactory);

// Setup Symfony Validator Metadata
$metadataFactory = new Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory(
    new Symfony\Component\Validator\Mapping\Loader\AttributeLoader()
);

$extractor = new SchemaExtractor(
    new ReflectionDiscoverer(setTitleFromClassName: true),
    [
        // new PhpDocumentorEnricher(),
        new PhpStanEnricher(),
        new SymfonyValidationEnricher($metadataFactory),
    ],
    // new SymfonySerializerStrategy($classMetadataFactory, $nameConverter),
    new Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy(),
    new StandardSchemaMapper(),
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
        $context = (new ExtractionContext())
            ->with(new SymfonySerializerContext());

        $schema = $extractor->extract($class, $context);
        echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "\e[1;31mERRORE: {$e->getMessage()}\e[0m\n";
    }
}

echo "\n\e[1;32mDone.\e[0m\n";
