<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Integration;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Discoverer\ReflectionDiscoverer;
use Zeusi\JsonSchemaExtractor\Enricher\PhpStanEnricher;
use Zeusi\JsonSchemaExtractor\Mapper\JsonSchemaDialect;
use Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapper;
use Zeusi\JsonSchemaExtractor\Mapper\StandardJsonSchemaMapperOptions;
use Zeusi\JsonSchemaExtractor\SchemaExtractor;
use Zeusi\JsonSchemaExtractor\Serialization\JsonEncodeSerializationStrategy;
use Zeusi\JsonSchemaExtractor\Tests\Fixtures\PhpDocObject;

final class JsonSchema202012ComplianceTest extends TestCase
{
    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    public function testGeneratedSchemasAreDraft202012Compliant(): void
    {
        $dialect = JsonSchemaDialect::Draft202012;
        $validation = self::validatorWithDraft202012MetaSchemas()->validate(
            self::generatedDraft202012SchemaDocument(),
            $dialect->schemaUri()
        );

        self::assertTrue($validation->isValid(), self::formatValidationFailure($validation));
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    public function testGeneratedSchemasDoNotUseDeprecatedDraft202012Keywords(): void
    {
        $dialect = JsonSchemaDialect::Draft202012;
        $validation = self::validatorWithDraft202012MetaSchemas(rejectDeprecatedKeywords: true)->validate(
            self::generatedDraft202012SchemaDocument(),
            $dialect->schemaUri()
        );

        self::assertTrue($validation->isValid(), self::formatValidationFailure($validation));
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    private static function generatedDraft202012SchemaDocument(): object
    {
        $extractor = new SchemaExtractor(
            new ReflectionDiscoverer(),
            [new PhpStanEnricher()],
            new JsonEncodeSerializationStrategy(),
            new StandardJsonSchemaMapper(new StandardJsonSchemaMapperOptions(
                dialect: JsonSchemaDialect::Draft202012,
                includeSchemaKeyword: true
            ))
        );

        return self::toJsonObject($extractor->extract(PhpDocObject::class));
    }

    /**
     * @param array<string, mixed>|object $value
     * @throws \JsonException
     */
    private static function toJsonObject(array|object $value): object
    {
        $json = json_encode($value, \JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertIsObject($decoded);

        return $decoded;
    }

    /**
     * @throws \JsonException
     */
    private static function formatValidationFailure(ValidationResult $validation): string
    {
        $error = $validation->error();
        if ($error === null) {
            return '';
        }

        return json_encode(
            (new ErrorFormatter())->formatOutput($error, 'detailed'),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT
        );
    }

    private static function validatorWithDraft202012MetaSchemas(bool $rejectDeprecatedKeywords = false): CompliantValidator
    {
        $resolver = new SchemaResolver();
        foreach (self::draft202012MetaSchemaFiles() as $file) {
            $schema = self::readJsonObject($file);
            if ($rejectDeprecatedKeywords) {
                $schema = self::rejectDeprecatedKeywordSchemas($schema);
            }

            self::assertTrue($resolver->registerRaw($schema));
        }

        return new CompliantValidator(new SchemaLoader(new SchemaParser(), $resolver));
    }

    /**
     * @throws \JsonException
     */
    private static function readJsonObject(string $file): object
    {
        $json = file_get_contents($file);
        self::assertIsString($json);

        $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertIsObject($decoded);

        return $decoded;
    }

    private static function rejectDeprecatedKeywordSchemas(mixed $value): mixed
    {
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::rejectDeprecatedKeywordSchemas($item);
            }

            return $value;
        }

        if (!$value instanceof \stdClass) {
            return $value;
        }

        if (($value->deprecated ?? false) === true) {
            return false;
        }

        foreach (get_object_vars($value) as $propertyName => $propertyValue) {
            $value->{$propertyName} = self::rejectDeprecatedKeywordSchemas($propertyValue);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function draft202012MetaSchemaFiles(): array
    {
        $basePath = __DIR__ . '/../Support/JsonSchemaMetaSchemas/2020-12';

        return [
            $basePath . '/schema.json',
            $basePath . '/meta/core.json',
            $basePath . '/meta/applicator.json',
            $basePath . '/meta/unevaluated.json',
            $basePath . '/meta/validation.json',
            $basePath . '/meta/meta-data.json',
            $basePath . '/meta/format-annotation.json',
            $basePath . '/meta/content.json',
        ];
    }
}
