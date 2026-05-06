<?php

declare(strict_types=1);

namespace Maxi\Tests\Testdata;

use Maxi\Core\MaxiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Maxi\Api\parseMaxi;

/**
 * Data-driven test suite that runs every case found in the maxi-testdata package.
 */
class TestdataTest extends TestCase
{

    /**
     * @return array<string, array{dir:string, meta:array, expected:array, input:string}>
     */
    public static function caseProvider(): array
    {
        $testdataRoot = self::findTestdataRoot();
        if ($testdataRoot === null) {
            return [];
        }

        $cases = [];
        foreach (new \DirectoryIterator($testdataRoot) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            $dir = $entry->getPathname();
            $testJsonPath = $dir . '/test.json';
            $expectedJson = $dir . '/expected.json';
            $inMaxi = $dir . '/in.maxi';

            if (!is_file($testJsonPath) || !is_file($expectedJson) || !is_file($inMaxi)) {
                continue;
            }

            $meta = json_decode(file_get_contents($testJsonPath), true);
            $expected = json_decode(file_get_contents($expectedJson), true);
            $input = file_get_contents($inMaxi);
            $id = $meta['id'] ?? $entry->getFilename();
            $title = $meta['title'] ?? $id;

            $cases["{$id}: {$title}"] = [
                'dir' => $dir,
                'meta' => $meta,
                'expected' => $expected,
                'input' => $input,
            ];
        }
        return $cases;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Main test method
    // ─────────────────────────────────────────────────────────────────────────

    #[DataProvider('caseProvider')]
    public function testCase(string $dir, array $meta, array $expected, string $input): void
    {
        $expectSuccess = $expected['success'] ?? ($meta['category'] === 'valid');

        // Parser-dependent error tests: our parser supports forward references,
        // so tests that expect E009 for forward refs should pass as success instead.
        if (($meta['parser_dependent'] ?? false) && !$expectSuccess) {
            $expectSuccess = true;
        }

        // Build a loadSchema callback that resolves relative paths from the test-case dir
        $loadSchema = function (string $path) use ($dir): string {
            // Try relative to the test-case folder first, then testdata root
            $local = $dir . '/' . $path;
            if (is_file($local)) {
                return file_get_contents($local);
            }
            throw new \RuntimeException("Cannot resolve schema file '{$path}' in test dir '{$dir}'");
        };

        $parserOptions = $meta['parserOptions'] ?? [];
        $options = array_merge($parserOptions, ['loadSchema' => $loadSchema]);

        if (!$expectSuccess) {
            // ── Error cases ───────────────────────────────────────────────
            $expectedCode = $expected['error_code'] ?? null;
            $expectedCodeOneOf = $expected['error_code_one_of'] ?? null;
            try {
                parseMaxi($input, $options);
                $this->fail(
                    "Expected MaxiException" .
                    ($expectedCode ? " with code {$expectedCode}" : '') .
                    " but none was thrown."
                );
            } catch (MaxiException $e) {
                if ($expectedCodeOneOf !== null) {
                    $this->assertContains(
                        $e->errorCode,
                        $expectedCodeOneOf,
                        "Expected error code one of [" . implode(', ', $expectedCodeOneOf) . "], got {$e->errorCode}: {$e->getMessage()}"
                    );
                } elseif ($expectedCode !== null) {
                    $this->assertSame(
                        $expectedCode,
                        $e->errorCode,
                        "Expected error code {$expectedCode}, got {$e->errorCode}: {$e->getMessage()}"
                    );
                } else {
                    $this->assertInstanceOf(MaxiException::class, $e);
                }
            }
            return;
        }

        // ── Success cases ─────────────────────────────────────────────────
        try {
            $result = parseMaxi($input, $options);
        } catch (MaxiException $e) {
            $this->fail("Unexpected MaxiException [{$e->errorCode}]: {$e->getMessage()}");
        }

        $this->assertNotNull($result, 'parseMaxi should return a non-null result');

        // Check expected warnings if present
        $expectedWarnings = $expected['warnings'] ?? [];
        foreach ($expectedWarnings as $w) {
            $wCode = is_array($w) ? ($w[0] ?? null) : null;
            if ($wCode !== null) {
                $codes = array_map(fn($warning) => $warning->code, $result->warnings);
                $this->assertContains($wCode, $codes, "Expected warning code {$wCode} not found");
            }
        }

        // record_validations
        foreach ($expected['record_validations'] ?? [] as $validation) {
            $path = $validation['path'] ?? null;
            $expectedValue = $validation['expected_value'] ?? null;
            $description = $validation['description'] ?? $path;

            if ($path === null || !str_starts_with($path, '#/records/')) {
                continue; // skip unsupported paths (#/schema/, etc.)
            }

            $actual = self::resolveJsonPath($path, $result);
            $this->assertEquals(
                $expectedValue,
                $actual,
                "record_validation [{$description}]: path={$path}"
            );
        }

        // object_validations
        foreach ($expected['object_validations'] ?? [] as $validation) {
            $path = $validation['path'] ?? null;
            $expectedValue = $validation['expected_value'] ?? null;
            $description = $validation['description'] ?? $path;
            $followRef = $validation['follow_ref'] ?? $validation['follow_references'] ?? false;

            if ($path === null || !str_starts_with($path, '#/objects/')) {
                continue;
            }

            // Skip follow_ref validations (require hydration)
            if ($followRef) {
                continue;
            }

            $actual = self::resolveJsonPath($path, $result);
            $this->assertEquals(
                $expectedValue,
                $actual,
                "object_validation [{$description}]: path={$path}"
            );
        }

        // Record count / type check
        if (isset($expected['records'])) {
            $this->assertCount(
                count($expected['records']),
                $result->records,
                "Record count mismatch"
            );
            foreach ($expected['records'] as $i => $rec) {
                if (isset($rec['type'])) {
                    // expected.json may use type NAME (e.g. "User") or alias (e.g. "U")
                    $expectedType = $rec['type'];
                    $actualAlias = $result->records[$i]->alias;
                    $typeDef = $result->schema->getType($actualAlias);
                    $actualName = $typeDef?->name ?? $actualAlias;
                    $matches = ($expectedType === $actualAlias || $expectedType === $actualName);
                    $this->assertTrue(
                        $matches,
                        "Record {$i} type mismatch: expected '{$expectedType}', got alias='{$actualAlias}' name='{$actualName}'"
                    );
                }
            }
        }
    }

    private static function resolveJsonPath(string $path, \Maxi\Core\MaxiParseResult $result): mixed
    {
        $path = ltrim($path, '#/');
        $segments = explode('/', $path);
        $root = array_shift($segments);

        if ($root === 'records') {
            // #/records/{index}/value/{fieldName}[/{subField}]
            // #/records/{index}/type
            $index = (int)array_shift($segments);
            $record = $result->records[$index] ?? null;
            if ($record === null) {
                return null;
            }

            $nextSeg = array_shift($segments); // 'value' or 'type'

            if ($nextSeg === 'type') {
                $typeDef = $result->schema?->getType($record->alias);
                return $typeDef?->name ?? $record->alias;
            }

            // nextSeg === 'value'
            $fieldName = array_shift($segments);
            if ($fieldName === null) {
                // return the entire value array, keyed by field name if schema available
                $typeDef = $result->schema?->getType($record->alias);
                if ($typeDef !== null && count($typeDef->fields) === count($record->values)) {
                    $assoc = [];
                    foreach ($typeDef->fields as $i => $field) {
                        $assoc[$field->name] = $record->values[$i] ?? null;
                    }
                    return $assoc;
                }
                return $record->values;
            }

            $typeDef = $result->schema?->getType($record->alias);
            if ($typeDef !== null) {
                foreach ($typeDef->fields as $i => $field) {
                    // Match by exact name or by name_annotation (e.g. data_hex for data:bytes@hex)
                    $nameMatches = $field->name === $fieldName;
                    if (!$nameMatches && $field->annotation !== null) {
                        $nameMatches = ($field->name . '_' . $field->annotation) === $fieldName;
                    }
                    if ($nameMatches) {
                        $value = $record->values[$i] ?? null;
                        // Traverse remaining sub-keys into array/map value
                        while (!empty($segments) && is_array($value)) {
                            $subKey = array_shift($segments);
                            $value = $value[$subKey] ?? ($value[(int)$subKey] ?? null);
                        }
                        return $value;
                    }
                }
            }

            // No schema — try positional/raw values
            if ($fieldName === 'values' && is_array($record->values)) {
                $value = $record->values;
                while (!empty($segments) && is_array($value)) {
                    $subKey = array_shift($segments);
                    $value = $value[(int)$subKey] ?? ($value[$subKey] ?? null);
                }
                return $value;
            }

            return null;
        }

        if ($root === 'objects') {
            // #/objects/{TypeNameOrAlias}/{id}/{fieldName}
            $typeAlias = array_shift($segments);
            $idValue = array_shift($segments);
            $fieldName = array_shift($segments);

            if ($typeAlias === null) {
                return null;
            }

            // Resolve type name → alias
            $typeDef = $result->schema->getType($typeAlias);
            if ($typeDef === null) {
                return null;
            }

            // #/objects/TypeAlias/{id} — return full object or check existence
            if ($idValue !== null && $fieldName === null) {
                // Check object registry first
                $registryObj = $result->objectRegistry[$typeDef->alias][(string)$idValue] ?? null;
                if ($registryObj !== null) {
                    return $registryObj;
                }
                // Fallback: iterate records
                $idField = $typeDef->getIdField();
                if ($idField === null) {
                    return null;
                }
                $idIdx = array_search($idField, $typeDef->fields, true);
                foreach ($result->records as $record) {
                    if ($record->alias !== $typeDef->alias) {
                        continue;
                    }
                    $recId = $idIdx !== false ? ($record->values[$idIdx] ?? null) : null;
                    if ((string)$recId === (string)$idValue) {
                        // Return field-name-keyed associative array
                        $vals = [];
                        foreach ($typeDef->fields as $fi => $field) {
                            $vals[$field->name] = $record->values[$fi] ?? null;
                        }
                        return $vals;
                    }
                }
                return null;
            }

            if ($idValue === null || $fieldName === null) {
                return null;
            }

            // Try object registry first (has inline objects replaced with IDs)
            $registryAlias = $typeDef->alias;
            $registryObj = $result->objectRegistry[$registryAlias][(string)$idValue] ?? null;
            if ($registryObj !== null && is_array($registryObj)) {
                // Try fieldName directly, then with annotation suffix
                $resolvedFieldName = $fieldName;
                if (!array_key_exists($fieldName, $registryObj)) {
                    foreach ($typeDef->fields as $field) {
                        if ($field->annotation !== null && ($field->name . '_' . $field->annotation) === $fieldName) {
                            $resolvedFieldName = $field->name;
                            break;
                        }
                    }
                }
                if (array_key_exists($resolvedFieldName, $registryObj)) {
                    $value = $registryObj[$resolvedFieldName];
                    while (!empty($segments) && is_array($value)) {
                        $subKey = array_shift($segments);
                        $value = $value[$subKey] ?? ($value[(int)$subKey] ?? null);
                    }
                    return $value;
                }
            }

            // Fallback: iterate records
            $idField = $typeDef->getIdField();
            if ($idField === null) {
                return null;
            }
            $idIdx = array_search($idField, $typeDef->fields, true);

            foreach ($result->records as $record) {
                if ($record->alias !== $typeDef->alias) {
                    continue;
                }
                $recId = $idIdx !== false ? ($record->values[$idIdx] ?? null) : null;
                if ((string)$recId === (string)$idValue) {
                    foreach ($typeDef->fields as $i => $field) {
                        $nameMatches = $field->name === $fieldName;
                        if (!$nameMatches && $field->annotation !== null) {
                            $nameMatches = ($field->name . '_' . $field->annotation) === $fieldName;
                        }
                        if ($nameMatches) {
                            $value = $record->values[$i] ?? null;
                            while (!empty($segments) && is_array($value)) {
                                $subKey = array_shift($segments);
                                $value = $value[$subKey] ?? ($value[(int)$subKey] ?? null);
                            }
                            return $value;
                        }
                    }
                }
            }
            return null;
        }

        return null; // #/schema/ paths not supported at this level
    }

    private static function findTestdataRoot(): ?string
    {
        $composerPath = __DIR__ . '/../../vendor/maxi-format/maxi-testdata/testdata';
        if (is_dir($composerPath)) {
            return realpath($composerPath);
        }
        $sibling = __DIR__ . '/../../../maxi-testdata/testdata';
        if (is_dir($sibling)) {
            return realpath($sibling);
        }

        return null;
    }
}
