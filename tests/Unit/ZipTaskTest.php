<?php

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Task\BaseTask;
use PhpMake\Utilities\Logger;

    /** Tests ZIP task structure, archive contents, and symlink handling. */
final class ZipTaskTest extends TestCase
{
    /**
     * This method is called before the first test method in the test class is executed.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {}

    /**
     * This method is called after the last test method in the test class has been executed.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        gc_collect_cycles();
    }

    /**
     * This method is called BEFORE each test method.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Methods initialization codes.
    }

    /**
     * This method is called AFTER each test method.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Methods finalization codes.
        parent::tearDown();
    }

    /** Ensures an archive created inside its source directory does not include itself. */
    public function testArchiveDoesNotIncludeItselfWhenOutputIsInsideSource(): void
    {
        // Arrange
        if (!class_exists(\ZipArchive::class)) {
        // Assert
            $this->markTestSkipped('ext-zip is not available.');
        }
        $root = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-zip-self-' . uniqid('', true);
        mkdir($root);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'input.txt', 'content');
        $output = $root . DIRECTORY_SEPARATOR . 'archive.zip';
        try {
            $task = new ZipTask(['source' => $root, 'output' => $output], new Logger(false, true, true));
        // Act
            $this->assertTrue($task->execute(false, true));
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($output));
            $this->assertSame(1, $zip->numFiles);
            $this->assertSame('input.txt', $zip->getNameIndex(0));
            $zip->close();
        } finally {
            if (file_exists($output)) {
                unlink($output);
            }
            if (file_exists($root . DIRECTORY_SEPARATOR . 'input.txt')) {
                unlink($root . DIRECTORY_SEPARATOR . 'input.txt');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }
}
