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

    #[DataProvider('caseProvider')]
    public function testCase(string $dir, array $meta, array $expected, string $input): void
    {
        $expectSuccess = $expected['success'] ?? ($meta['category'] === 'valid');

        if (($meta['parser_dependent'] ?? false) && !$expectSuccess) {
            $expectSuccess = true;
        }

        $loadSchema = function (string $path) use ($dir): string {
            $local = $dir . '/' . $path;
            if (is_file($local)) {
                return file_get_contents($local);
            }
            throw new \RuntimeException("Cannot resolve schema file '{$path}' in test dir '{$dir}'");
        };

        $parserOptions = $meta['parserOptions'] ?? [];
        $options = array_merge($parserOptions, ['loadSchema' => $loadSchema]);

        if (!$expectSuccess) {
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

        try {
            $result = parseMaxi($input, $options);
        } catch (MaxiException $e) {
            $this->fail("Unexpected MaxiException [{$e->errorCode}]: {$e->getMessage()}");
        }

        $this->assertNotNull($result, 'parseMaxi should return a non-null result');

        $expectedWarnings = $expected['warnings'] ?? [];
        foreach ($expectedWarnings as $w) {
            $wCode = is_array($w) ? ($w[0] ?? null) : null;
            if ($wCode !== null) {
                $codes = array_map(fn($warning) => $warning->code, $result->warnings);
                $this->assertContains($wCode, $codes, "Expected warning code {$wCode} not found");
            }
        }

        foreach ($expected['record_validations'] ?? [] as $validation) {
            $path = $validation['path'] ?? null;
            $expectedValue = $validation['expected_value'] ?? null;
            $description = $validation['description'] ?? $path;

            if ($path === null || !str_starts_with($path, '#/records/')) {
                continue;
            }

            $actual = self::resolveJsonPath($path, $result);
            $this->assertEquals(
                $expectedValue,
                $actual,
                "record_validation [{$description}]: path={$path}"
            );
        }

        foreach ($expected['object_validations'] ?? [] as $validation) {
            $path = $validation['path'] ?? null;
            $expectedValue = $validation['expected_value'] ?? null;
            $description = $validation['description'] ?? $path;
            $followRef = $validation['follow_ref'] ?? $validation['follow_references'] ?? false;

            if ($path === null || !str_starts_with($path, '#/objects/')) {
                continue;
            }

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

        if (isset($expected['records'])) {
            $this->assertCount(
                count($expected['records']),
                $result->records,
                "Record count mismatch"
            );
            foreach ($expected['records'] as $i => $rec) {
                if (isset($rec['type'])) {
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
            $index = (int)array_shift($segments);
            $record = $result->records[$index] ?? null;
            if ($record === null) {
                return null;
            }

            $nextSeg = array_shift($segments);

            if ($nextSeg === 'type') {
                $typeDef = $result->schema?->getType($record->alias);
                return $typeDef?->name ?? $record->alias;
            }

            $fieldName = array_shift($segments);
            if ($fieldName === null) {
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
            $typeAlias = array_shift($segments);
            $idValue = array_shift($segments);
            $fieldName = array_shift($segments);

            if ($typeAlias === null) {
                return null;
            }

            $typeDef = $result->schema->getType($typeAlias);
            if ($typeDef === null) {
                return null;
            }

            if ($idValue !== null && $fieldName === null) {
                $registryObj = $result->objectRegistry[$typeDef->alias][(string)$idValue] ?? null;
                if ($registryObj !== null) {
                    return $registryObj;
                }
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

            $registryAlias = $typeDef->alias;
            $registryObj = $result->objectRegistry[$registryAlias][(string)$idValue] ?? null;
            if ($registryObj !== null && is_array($registryObj)) {
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

        return null;
    }

    private static function findTestdataRoot(): ?string
    {
        $composerPath = __DIR__ . '/../../vendor/maxi-format/maxi-testdata/testdata';
        if (is_dir($composerPath)) {
            return realpath($composerPath);
        }

        return null;
    }
}
