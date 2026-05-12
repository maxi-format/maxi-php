<?php

declare(strict_types=1);

namespace Maxi\Api;

use Maxi\Core\MaxiErrorCode;
use Maxi\Core\MaxiException;
use Maxi\Core\MaxiParseResult;
use Maxi\Core\MaxiRecord;
use Maxi\Core\MaxiSchema;
use Maxi\Core\MaxiWarning;
use Maxi\Internal\RecordParser;
use Maxi\Internal\SchemaParser;

/**
 * Streaming parse result.
 *
 * The schema is fully parsed before this object is returned.
 * Records are yielded lazily via the generator returned by records().
 *
 * Implements IteratorAggregate so you can use it directly in foreach.
 *
 * @implements \IteratorAggregate<int, MaxiRecord>
 */
class MaxiStreamResult implements \IteratorAggregate
{
    /** @var \Generator<int,MaxiRecord> */
    private \Generator $generator;

    /** Keep the full parse result so warnings accumulated during iteration are visible. */
    private MaxiParseResult $parseResult;

    public function __construct(
        public readonly MaxiSchema $schema,
        \Generator                 $generator,
        MaxiParseResult            $result,
    ) {
        $this->generator = $generator;
        $this->parseResult = $result;
    }

    /** @return MaxiWarning[] Warnings accumulated so far (grows as records are consumed). */
    public function getWarnings(): array
    {
        return $this->parseResult->warnings;
    }

    /**
     * @return MaxiWarning[]
     * @deprecated Use getWarnings() for live warnings; this property snapshot is captured at construction.
     */
    public function __get(string $name): mixed
    {
        if ($name === 'warnings') {
            return $this->parseResult->warnings;
        }
        throw new \RuntimeException("Undefined property: {$name}");
    }

    /**
     * Generator that lazily yields MaxiRecord instances.
     * Each call returns the same generator (single-pass).
     *
     * @return \Generator<int,MaxiRecord>
     */
    public function records(): \Generator
    {
        yield from $this->generator;
    }

    /**
     * @return \Generator<int,MaxiRecord>
     */
    public function getIterator(): \Generator
    {
        return $this->records();
    }
}

/**
 * Parse MAXI input in streaming mode.
 *
 * Phase 1 (schema) completes synchronously before this function returns.
 * Phase 2 (records) is lazy: records are parsed on-demand via MaxiStreamResult::records().
 *
 * $input may be either a string or a resource (file handle).
 * For resources the full content is read once via stream_get_contents() before parsing.
 * (True chunk-based streaming can be added later by feeding to the generator loop.)
 *
 * @param string|resource $input
 * @param array{filename?:string|null,loadSchema?:callable|null} $options
 */
function streamMaxi(mixed $input, array $options = []): MaxiStreamResult
{
    if (is_resource($input)) {
        $content = stream_get_contents($input);
        if ($content === false) {
            throw new MaxiException(
                'Failed to read from stream resource',
                MaxiErrorCode::StreamError,
            );
        }
        $input = $content;
    }

    $result = new MaxiParseResult();

    ['schemaSection' => $schemaSection, 'recordsSection' => $recordsSection] = splitSections($input);

    (new SchemaParser($schemaSection, $result, $options))->parse();

    $generator = generateRecords($recordsSection ?? '', $result, $options);

    return new MaxiStreamResult($result->schema, $generator, $result);
}

/**
 * @param array<string,mixed> $options
 * @return \Generator<int, MaxiRecord>
 */
function generateRecords(string $recordsText, MaxiParseResult $result, array $options): \Generator
{
    if (trim($recordsText) === '') {
        return;
    }

    $parser = new RecordParser($recordsText, $result, $options);
    $text = $recordsText;
    $len = strlen($text);
    $i = 0;
    $lineNumber = 1;
    $filename = $options['filename'] ?? null;

    while ($i < $len) {
        $ch = $text[$i];

        if ($ch === "\n") {
            $lineNumber++;
            $i++;
            continue;
        }
        if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
            $i++;
            continue;
        }

        if ($ch === '#') {
            while ($i < $len && $text[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        $cc = ord($ch);
        if (!(($cc >= 65 && $cc <= 90) || ($cc >= 97 && $cc <= 122) || $cc === 95)) {
            $i++;
            continue;
        }

        $aliasStart = $i;
        $i++;
        while ($i < $len) {
            $c = ord($text[$i]);
            if (($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122)
                || ($c >= 48 && $c <= 57) || $c === 45 || $c === 95) {
                $i++;
            } else {
                break;
            }
        }
        $alias = substr($text, $aliasStart, $i - $aliasStart);

        while ($i < $len && ($text[$i] === ' ' || $text[$i] === "\t" || $text[$i] === "\r")) {
            $i++;
        }

        if ($i >= $len || $text[$i] !== '(') {
            continue;
        }

        $recordLine = $lineNumber;
        $i++;
        $valuesStart = $i;

        $parenDepth = 1;
        $bracketDepth = 0;
        $braceDepth = 0;
        $inString = false;
        $escapeNext = false;

        while ($i < $len) {
            $c = $text[$i];

            if ($c === "\n") {
                $lineNumber++;
            }

            if ($escapeNext) {
                $escapeNext = false;
                $i++;
                continue;
            }
            if ($inString) {
                if ($c === '\\') {
                    $escapeNext = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                $i++;
                continue;
            }
            if ($c === '"') {
                $inString = true;
                $i++;
                continue;
            }
            if ($c === '(') {
                $parenDepth++;
            } elseif ($c === ')') {
                $parenDepth--;
                if ($parenDepth === 0) {
                    break;
                }
            } elseif ($c === '[') {
                $bracketDepth++;
            } elseif ($c === ']') {
                $bracketDepth = max(0, $bracketDepth - 1);
            } elseif ($c === '{') {
                $braceDepth++;
            } elseif ($c === '}') {
                $braceDepth = max(0, $braceDepth - 1);
            }
            $i++;
        }

        if ($i >= $len || $text[$i] !== ')' || $parenDepth !== 0
            || $bracketDepth !== 0 || $braceDepth !== 0) {
            if ($bracketDepth !== 0) {
                throw new MaxiException(
                    "Malformed array: unmatched bracket in record '{$alias}'",
                    MaxiErrorCode::ArraySyntaxError,
                    $recordLine,
                    null,
                    $filename,
                );
            }
            throw new MaxiException(
                "Unclosed record parentheses for '{$alias}'",
                MaxiErrorCode::InvalidSyntaxError,
                $recordLine,
                null,
                $filename,
            );
        }

        $valuesStr = substr($text, $valuesStart, $i - $valuesStart);
        $i++;

        yield $parser->parseSingleRecord($alias, $valuesStr, $recordLine);
    }
}
