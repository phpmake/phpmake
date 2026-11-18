<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildConfiguration;
use PhpMake\Build\BuildExecutor;
use PhpMake\Target\DependencyResolver;
use PhpMake\Task\TaskExecutor;
use PhpMake\Task\TaskFactory;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests build orchestration and target selection failures. */
final class BuildExecutorTest extends TestCase
{
    /** Executing an undefined target throws a descriptive exception. */
    public function testExecuteRejectsMissingTarget(): void
    {
        // Arrange
        $logger = new Logger(false, true, true);
        $config = new BuildConfiguration(
            'BuildExecutorTest',
            'Missing target',
            '1.0.0',
            ['build' => ['tasks' => []]],
            'build'
        );
        $executor = new BuildExecutor(
            new TaskFactory($logger),
            new DependencyResolver(),
            new TaskExecutor()
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Target 'missing' not found.");
        // Act
        $executor->execute($config, 'missing', $logger);
    }
}
