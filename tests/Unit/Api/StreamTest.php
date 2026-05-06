<?php

declare(strict_types=1);

namespace Maxi\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

use function Maxi\Api\streamMaxi;

class StreamTest extends TestCase
{
    public function testStreamYieldsRecords(): void
    {
        $input = "U:User(id:int|name)\n###\nU(1|Alice)\nU(2|Bob)";
        $stream = streamMaxi($input);

        $this->assertNotNull($stream->schema);
        $this->assertNotNull($stream->schema->getType('U'));

        $records = [];
        foreach ($stream as $record) {
            $records[] = $record;
        }

        $this->assertCount(2, $records);
        $this->assertSame('U', $records[0]->alias);
        $this->assertSame(1, $records[0]->values[0]);
        $this->assertSame('Alice', $records[0]->values[1]);
    }

    public function testStreamSchemaAvailableBeforeIteration(): void
    {
        $input = "U:User(id:int|name)\n###\nU(1|Alice)";
        $stream = streamMaxi($input);

        // Schema must be resolved before any record is iterated
        $this->assertTrue($stream->schema->hasType('U'));
    }

    public function testStreamEmptyRecords(): void
    {
        $input = "U:User(id:int|name)\n###";
        $stream = streamMaxi($input);

        $records = iterator_to_array($stream->records());
        $this->assertEmpty($records);
    }

    public function testStreamWorksOnResource(): void
    {
        $input = "U:User(id:int|name)\n###\nU(1|Alice)";
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, $input);
        rewind($resource);

        $stream = streamMaxi($resource);
        $records = iterator_to_array($stream->records());
        $this->assertCount(1, $records);
        fclose($resource);
    }

    public function testStreamRecordsGeneratorIsLazy(): void
    {
        $input = "U:User(id:int|name)\n###\nU(1|Alice)\nU(2|Bob)\nU(3|Charlie)";
        $stream = streamMaxi($input);
        $generator = $stream->records();

        // Advance one at a time
        $generator->current(); // prime
        $first = $generator->current();
        $this->assertSame('U', $first->alias);
        $this->assertSame(1, $first->values[0]);

        $generator->next();
        $second = $generator->current();
        $this->assertSame(2, $second->values[0]);
    }

    public function testStreamWarningsAvailable(): void
    {
        // Unknown alias warns by default
        $input = "###\nX(1|2)";
        $stream = streamMaxi($input);
        // Consume all records to populate warnings
        iterator_to_array($stream->records());
        $this->assertNotEmpty($stream->getWarnings());
    }
}
