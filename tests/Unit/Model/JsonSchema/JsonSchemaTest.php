<?php

namespace Zeusi\JsonSchemaExtractor\Tests\Unit\Model\JsonSchema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\JsonSchema;
use Zeusi\JsonSchemaExtractor\Model\JsonSchema\SchemaType;

#[CoversClass(JsonSchema::class)]
class JsonSchemaTest extends TestCase
{
    public function testJsonSerializeReturnsReferenceOnlySchema(): void
    {
        $schema = (new JsonSchema())
            ->setRef('#/definitions/User')
            ->setType(SchemaType::OBJECT)
            ->setTitle('Ignored');

        self::assertSame(['$ref' => '#/definitions/User'], $schema->jsonSerialize());
    }

    public function testJsonSerializeIncludesNonReferenceKeywords(): void
    {
        $schema = (new JsonSchema())
            ->setType(SchemaType::OBJECT)
            ->setFormat('custom-format')
            ->setTitle('User')
            ->setDescription('A user schema')
            ->addProperty('id', (new JsonSchema())->setType(SchemaType::INTEGER), true)
            ->setItems((new JsonSchema())->setType(SchemaType::STRING))
            ->setOneOf([(new JsonSchema())->setType(SchemaType::STRING)])
            ->setAnyOf([(new JsonSchema())->setType(SchemaType::INTEGER)])
            ->setAllOf([(new JsonSchema())->setType(SchemaType::OBJECT)])
            ->setEnum(['active', 'inactive'])
            ->setMinLength(1)
            ->setMaxLength(10)
            ->setMinItems(1)
            ->setMaxItems(3)
            ->setMinimum(1)
            ->setMaximum(100)
            ->setExclusiveMinimum(0)
            ->setExclusiveMaximum(101)
            ->setMultipleOf(5)
            ->setPattern('^[a-z]+$')
            ->setDeprecated(true)
            ->setExamples(['example'])
            ->setDefault('active')
            ->setAdditionalProperties((new JsonSchema())->setType(SchemaType::STRING))
            ->setDefinitions(['Address' => (new JsonSchema())->setType(SchemaType::OBJECT)]);

        $serialized = $schema->jsonSerialize();

        self::assertSame([
            'type' => 'object',
            'format' => 'custom-format',
            'title' => 'User',
            'description' => 'A user schema',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'items' => ['type' => 'string'],
            'oneOf' => [
                ['type' => 'string'],
            ],
            'anyOf' => [
                ['type' => 'integer'],
            ],
            'allOf' => [
                ['type' => 'object'],
            ],
            'required' => ['id'],
            'enum' => ['active', 'inactive'],
            'minLength' => 1,
            'maxLength' => 10,
            'minItems' => 1,
            'maxItems' => 3,
            'minimum' => 1,
            'maximum' => 100,
            'exclusiveMinimum' => 0,
            'exclusiveMaximum' => 101,
            'multipleOf' => 5,
            'pattern' => '^[a-z]+$',
            'deprecated' => true,
            'examples' => ['example'],
            'default' => 'active',
            'additionalProperties' => ['type' => 'string'],
            'definitions' => [
                'Address' => ['type' => 'object'],
            ],
        ], $serialized);
    }

    public function testJsonSerializeCanUseDefsKeyword(): void
    {
        $schema = (new JsonSchema())
            ->setDefs(['Address' => (new JsonSchema())->setType(SchemaType::OBJECT)]);

        self::assertSame([
            '$defs' => [
                'Address' => ['type' => 'object'],
            ],
        ], $schema->jsonSerialize());
    }
}
