<?php

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Task\BaseTask;
use ZipArchive;

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

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testZipTaskExtendsBaseTask(): void
    {
        // Arrange
        $reflection = new \ReflectionClass(ZipTask::class);
        // Action/Assert
        $this->assertTrue($reflection->isSubclassOf(BaseTask::class));
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testGetTypeMethodReturnsString(): void
    {
        // Arrange
        $method = new \ReflectionMethod(ZipTask::class, 'getType');
        // Action/Assert
        $this->assertTrue($method->isPublic());
        $this->assertEquals('string', $method->getReturnType()->getName());
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testValidateParamsMethod(): void
    {
        // Arrange
        $method = new \ReflectionMethod(ZipTask::class, 'validateParams');
        // Action/Assert
        $this->assertTrue($method->isProtected());
        $this->assertCount(0, $method->getParameters()); // No parameters.
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testAddFilesToZipIsPrivate(): void
    {
        // Arrange
        $method = new \ReflectionMethod(ZipTask::class, 'addFilesToZip');
        // Action/Assert
        $this->assertTrue($method->isPrivate());
        // Action/Assert
        $this->assertEquals('void', $method->getReturnType()->getName());
    }
}
