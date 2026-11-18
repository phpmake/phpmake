<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Task\BaseTask;
use PHPUnit\Framework\TestCase;

    /** Tests the shared task contract and base-class behavior. */
final class BaseTaskTest extends TestCase
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
    public function testBaseTaskHasRequiredMethods(): void
    {
        // Arrange
        $reflection = new \ReflectionClass(BaseTask::class);
        // Action/Assert
        // Assert
        $this->assertTrue($reflection->hasMethod('getType'));
        $this->assertTrue($reflection->hasMethod('validateParams'));
    }

    /** The base implementation returns the concrete task class as its type. */
    public function testBaseTaskReturnsConcreteClassNameByDefault(): void
    {
        // Arrange
        $task = new class([], new \PhpMake\Utilities\Logger(false, true, true)) extends BaseTask {
            /** Performs run task for the tests. */
            protected function runTask(bool $debug, bool $silent): bool
            {
                return true;
            }

            /** Performs validate params for the tests. */
            protected function validateParams(): void {}
        };

        // Assert
        // Act
        self::assertSame($task::class, $task->getType());
        self::assertTrue($task->execute(false, true));
    }

    /** Exceptions thrown by task implementations are converted to a failed result. */
    public function testBaseTaskConvertsExecutionExceptionToFailure(): void
    {
        // Arrange
        $task = new class([], new \PhpMake\Utilities\Logger(false, true, true)) extends BaseTask {
            /** Performs run task for the tests. */
            protected function runTask(bool $debug, bool $silent): bool
            {
                throw new \RuntimeException('Expected task failure');
            }

            /** Performs validate params for the tests. */
            protected function validateParams(): void {}
        };

        // Assert
        // Act
        self::assertFalse($task->execute(false, true));
    }

}
