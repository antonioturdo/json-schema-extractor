<?php

namespace Zeusi\JsonSchemaGenerator\JsonSchema;

/**
 * Common JSON Schema formats for documentation and type safety.
 * This can be used to populate the format field in PropertyDefinition or Schema.
 *
 * @see https://json-schema.org/understanding-json-schema/reference/string.html#format
 */
enum Format: string
{
    case DateTime = 'date-time';
    case Date = 'date';
    case Time = 'time';
    case Duration = 'duration';
    case Email = 'email';
    case IdnEmail = 'idn-email';
    case Hostname = 'hostname';
    case IdnHostname = 'idn-hostname';
    case IPv4 = 'ipv4';
    case IPv6 = 'ipv6';
    case Uri = 'uri';
    case UriReference = 'uri-reference';
    case Iri = 'iri';
    case IriReference = 'iri-reference';
    case Uuid = 'uuid';
    case JsonPointer = 'json-pointer';
    case RelativeJsonPointer = 'relative-json-pointer';
    case Regex = 'regex';
}
