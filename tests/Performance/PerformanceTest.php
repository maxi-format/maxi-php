<?php

declare(strict_types=1);

namespace Maxi\Tests\Performance;

use PHPUnit\Framework\TestCase;

use function Maxi\Api\dumpMaxi;
use function Maxi\Api\parseMaxi;

/**
 * Performance benchmark: MAXI vs JSON parsing and dumping.
 *
 * Run with the default size (100 000 records):
 *
 *   php vendor/bin/phpunit --testsuite Performance
 *
 * Override the record count via environment variables:
 *
 *   MAXI_BENCH_SIZE=1000000 php vendor/bin/phpunit --testsuite Performance
 *   MAXI_DUMP_BENCH_SIZE=1000000 php vendor/bin/phpunit --testsuite Performance
 *
 * The benchmark prints timing, throughput (rec/s), payload size, and the
 * MAXI-to-JSON ratio to stdout (visible with --debug or -v).
 */
class PerformanceTest extends TestCase
{
    private static int $dataSize;
    private static int $dumpSize;

    public static function setUpBeforeClass(): void
    {
        self::$dataSize = (int)(getenv('MAXI_BENCH_SIZE') ?: 100_000);
        self::$dumpSize = (int)(getenv('MAXI_DUMP_BENCH_SIZE') ?: 100_000);
    }

    public function testPerformanceParse(): void
    {
        $count = self::$dataSize;

        echo "\n--- Generating {$count} records for parse benchmark ---\n";

        $maxiString = self::buildMaxiString($count);
        $jsonString = self::buildJsonString($count);

        echo sprintf("MAXI size: %d KB\n", (int)(strlen($maxiString) / 1024));
        echo sprintf("JSON size: %d KB\n", (int)(strlen($jsonString) / 1024));

        // Warmup — fill opcode cache, stabilise timings
        parseMaxi(self::buildMaxiString(100));
        json_decode(self::buildJsonString(100), true);

        // MAXI parse
        $t0 = hrtime(true);
        $maxiResult = parseMaxi($maxiString);
        $maxiMs = (hrtime(true) - $t0) / 1_000_000;
        $maxiRecS = (int)($count * 1000 / $maxiMs);
        echo sprintf("MAXI parse time: %.1f ms  (%s rec/s)\n", $maxiMs, number_format($maxiRecS));

        // JSON parse
        $t0 = hrtime(true);
        $jsonResult = json_decode($jsonString, true);
        $jsonMs = (hrtime(true) - $t0) / 1_000_000;
        $jsonRecS = (int)($count * 1000 / $jsonMs);
        echo sprintf("JSON parse time: %.1f ms  (%s rec/s)\n", $jsonMs, number_format($jsonRecS));

        $ratio = $maxiMs / $jsonMs;
        echo sprintf("MAXI/JSON ratio: %.2fx\n", $ratio);

        // Correctness assertions
        $this->assertCount($count, $maxiResult->records, 'MAXI record count mismatch');
        $this->assertCount($count, $jsonResult, 'JSON record count mismatch');
    }

    public function testPerformanceDump(): void
    {
        $count = self::$dumpSize;

        echo "\n--- Generating {$count} records for dump benchmark ---\n";

        $users = self::generateUsers($count);

        // Warmup
        dumpMaxi(['U' => array_slice($users, 0, 10)], [
            'types' => self::MAXI_USER_TYPES,
            'collectReferences' => false,
        ]);
        json_encode(array_slice($users, 0, 10));

        // MAXI dump
        $t0 = hrtime(true);
        $maxiOutput = dumpMaxi(['U' => $users], [
            'types' => self::MAXI_USER_TYPES,
            'collectReferences' => false,
        ]);
        $maxiMs = (hrtime(true) - $t0) / 1_000_000;
        $maxiRecS = (int)($count * 1000 / $maxiMs);
        echo sprintf("MAXI dump size: %d KB\n", (int)(strlen($maxiOutput) / 1024));
        echo sprintf("MAXI dump time: %.1f ms  (%s rec/s)\n", $maxiMs, number_format($maxiRecS));

        // JSON dump
        $t0 = hrtime(true);
        $jsonOutput = json_encode($users);
        $jsonMs = (hrtime(true) - $t0) / 1_000_000;
        $jsonRecS = (int)($count * 1000 / $jsonMs);
        echo sprintf("JSON dump size: %d KB\n", (int)(strlen($jsonOutput) / 1024));
        echo sprintf("JSON dump time: %.1f ms  (%s rec/s)\n", $jsonMs, number_format($jsonRecS));

        $ratio = $maxiMs / $jsonMs;
        echo sprintf("MAXI/JSON ratio: %.2fx\n", $ratio);

        // Correctness assertions
        $this->assertStringContainsString('###', $maxiOutput, 'MAXI output missing separator');
        $this->assertStringContainsString('U(', $maxiOutput, 'MAXI output missing records');
        $this->assertStringStartsWith('[', $jsonOutput, 'JSON output malformed');
        $this->assertStringContainsString('"id"', $jsonOutput, 'JSON output missing id field');
    }

    private static function buildMaxiString(int $count): string
    {
        $parts = [
            "U:User(id:int|name|email:str@email|role:enum[admin,user]|createdAt:str@datetime|logins:int|active:bool)\n###\n",
        ];

        for ($i = 1; $i <= $count; $i++) {
            $name = "User {$i}";
            $email = "user{$i}@example.com";
            $role = ($i % 5 === 0) ? 'admin' : 'user';
            $createdAt = sprintf('2023-10-27T10:00:%02d.000Z', $i % 60);
            $logins = $i % 10;
            $active = ($i % 2 === 0) ? 'true' : 'false';
            $parts[] = "U({$i}|{$name}|{$email}|{$role}|{$createdAt}|{$logins}|{$active})\n";
        }

        return implode('', $parts);
    }

    private static function buildJsonString(int $count): string
    {
        $parts = ['['];

        for ($i = 1; $i <= $count; $i++) {
            $sep = ($i === 1) ? '' : ',';
            $role = ($i % 5 === 0) ? 'admin' : 'user';
            $createdAt = sprintf('2023-10-27T10:00:%02d.000Z', $i % 60);
            $active = ($i % 2 === 0) ? 'true' : 'false';

            $parts[] = $sep .
                '{"id":' . $i .
                ',"name":"User ' . $i . '"' .
                ',"email":"user' . $i . '@example.com"' .
                ',"role":"' . $role . '"' .
                ',"createdAt":"' . $createdAt . '"' .
                ',"logins":' . ($i % 10) .
                ',"active":' . $active .
                '}';
        }

        $parts[] = ']';

        return implode('', $parts);
    }

    /** @return array<int, array<string, mixed>> */
    private static function generateUsers(int $count): array
    {
        $out = [];

        for ($i = 1; $i <= $count; $i++) {
            $out[] = [
                'id' => $i,
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'role' => ($i % 5 === 0) ? 'admin' : 'user',
                'createdAt' => sprintf('2023-10-27T10:00:%02d.000Z', $i % 60),
                'logins' => $i % 10,
                'active' => $i % 2 === 0,
            ];
        }

        return $out;
    }

    /**
     * Type descriptor for the User type — mirrors the JS/Python benchmarks exactly.
     */
    private const MAXI_USER_TYPES = [[
        'alias' => 'U',
        'name' => 'User',
        'fields' => [
            ['name' => 'id', 'typeExpr' => 'int'],
            ['name' => 'name'],
            ['name' => 'email', 'typeExpr' => 'str', 'annotation' => 'email'],
            ['name' => 'role', 'typeExpr' => 'enum[admin,user]'],
            ['name' => 'createdAt', 'typeExpr' => 'str', 'annotation' => 'datetime'],
            ['name' => 'logins', 'typeExpr' => 'int'],
            ['name' => 'active', 'typeExpr' => 'bool'],
        ],
    ]];
}
