<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

final class PathSafetyTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger(false, true, true);
    }

    /**
     * @test
     *
     * @small
     */
    public function testDeleteRejectsPathOutsideProject(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        new DeleteTask(['path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'outside.txt'], $this->logger);
    }

    /**
     * @test
     *
     * @small
     */
    public function testCopyRejectsSourceOutsideProject(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        new CopyTask([
            'source' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'src.txt',
            'dest'   => 'inside.txt',
        ], $this->logger);
    }

    /**
     * @test
     *
     * @small
     */
    public function testCreateDirectoryRejectsParentTraversal(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        new CreateDirectoryTask(['name' => '..' . DIRECTORY_SEPARATOR . 'outside-dir'], $this->logger);
    }

    /**
     * @test
     *
     * @small
     */
    public function testArchiveRejectsOutputOutsideProject(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        new ZipTask([
            'source' => 'src',
            'output' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'out.zip',
        ], $this->logger);
    }

    /**
     * @test
     *
     * @small
     */
    public function testCreateDirectoryIsIdempotent(): void
    {
        $dir = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-test-idempotent-' . uniqid('', true);

        $task = new CreateDirectoryTask(['name' => $dir], $this->logger);
        $this->assertTrue($task->execute(false, true));
        $this->assertTrue(is_dir($dir));

        // Second run must succeed when directory already exists.
        $task2 = new CreateDirectoryTask(['name' => $dir], $this->logger);
        $this->assertTrue($task2->execute(false, true));

        rmdir($dir);
    }
}
