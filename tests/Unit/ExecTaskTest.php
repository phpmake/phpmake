<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\ExecTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

final class ExecTaskTest extends TestCase
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
    public function testExecTaskWithSafeCommand(): void
    {
        $logger = new Logger(false, true, true);
        $task = new ExecTask(['command' => 'echo "Hello World"'], $logger);
        $result = $task->execute(false, true);
        $this->assertTrue($result);
    }
}
