<?php

namespace Procorad\ProcostatReporting\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use Procorad\ProcostatReporting\Infrastructure\LocalFileStorage;

class LocalFileStorageTest extends TestCase
{
    public function test_it_saves_file_locally()
    {
        $tempDir = sys_get_temp_dir() . '/procostat_test';

        $storage = new LocalFileStorage($tempDir);

        $storage->save(
            'charts/test.png',
            'fake-binary-content'
        );

        $this->assertFileExists(
            $tempDir . '/charts/test.png'
        );

        $this->assertEquals(
            'fake-binary-content',
            file_get_contents($tempDir . '/charts/test.png')
        );
    }
}
