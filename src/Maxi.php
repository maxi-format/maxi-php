<?php

declare(strict_types=1);

namespace Maxi;

use Maxi\Api\MaxiStreamResult;
use Maxi\Core\MaxiHydrateResult;
use Maxi\Core\MaxiParseResult;

use function Maxi\Api\dumpMaxi;
use function Maxi\Api\dumpMaxiAuto;
use function Maxi\Api\parseMaxi;
use function Maxi\Api\parseMaxiAs;
use function Maxi\Api\parseMaxiAutoAs;
use function Maxi\Api\streamMaxi;

/**
 * Static facade for the MAXI format library.
 *
 * Provides a single entry point for all public operations:
 * parsing, dumping, streaming, and hydration.
 *
 * @example
 *   $result = Maxi::parse($input);
 *   $maxi   = Maxi::dump($result);
 */
final class Maxi
{
    /** @codeCoverageIgnore */
    private function __construct()
    {
    }

    /**
     * Parse MAXI text into a structured result containing schema and raw records.
     *
     * @param string $input MAXI-formatted text
     * @param array{
     *     
     *     filename?:   string|null,
     *     loadSchema?: callable|null,
     * } $options
     */
    public static function parse(string $input, array $options = []): MaxiParseResult
    {
        return parseMaxi($input, $options);
    }

    /**
     * Serialize data back to MAXI text.
     *
     * Accepts:
     *  - A MaxiParseResult (round-trip)
     *  - An array of rows with options['defaultAlias'] and options['types']
     *  - An associative array mapping alias → rows
     *
     * @param mixed $data Data to serialize
     * @param array{
     *     multiline?:        bool,
     *     includeTypes?:     bool,
     *     version?:          string|null,
     *     
     *     schemaFile?:       string|null,
     *     types?:            array|null,
     *     defaultAlias?:     string|null,
     *     collectReferences?:bool,
     * } $options
     */
    public static function dump(mixed $data, array $options = []): string
    {
        return dumpMaxi($data, $options);
    }

    /**
     * Parse MAXI text in streaming mode.
     *
     * The schema section is parsed eagerly (before this method returns).
     * Records are yielded lazily via the returned MaxiStreamResult.
     *
     * @param string|resource $input MAXI text or a readable file handle
     * @param array{
     *     
     *     filename?:   string|null,
     *     loadSchema?: callable|null,
     * } $options
     */
    public static function stream(mixed $input, array $options = []): MaxiStreamResult
    {
        return streamMaxi($input, $options);
    }

    /**
     * Parse MAXI text and hydrate records into class instances.
     *
     * @param string $input MAXI text
     * @param array<string,class-string> $classMap alias → FQCN (e.g. ['U' => User::class])
     * @param array $options Same options as parse()
     */
    public static function parseAs(string $input, array $classMap, array $options = []): MaxiHydrateResult
    {
        return parseMaxiAs($input, $classMap, $options);
    }

    /**
     * Parse MAXI text and hydrate records into class instances,
     * with aliases inferred automatically from #[MaxiType] attributes
     * or static $maxiSchema on each class.
     *
     * @param string $input MAXI text
     * @param class-string[] $classes e.g. [User::class, Order::class]
     * @param array $options Same options as parse()
     */
    public static function parseAutoAs(string $input, array $classes, array $options = []): MaxiHydrateResult
    {
        return parseMaxiAutoAs($input, $classes, $options);
    }

    /**
     * Dump an array of PHP objects to MAXI text,
     * with schema inferred from #[MaxiType]/#[MaxiField] attributes
     * or static $maxiSchema on each object's class.
     *
     * @param object[] $objects Instances to serialize
     * @param array $options Same options as dump()
     */
    public static function dumpAuto(array $objects, array $options = []): string
    {
        return dumpMaxiAuto($objects, $options);
    }
}
