<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpMake\Build\BuildConfiguration;
use PhpMake\Build\BuildExecutor;
use PhpMake\Build\BuildValidator;
use PhpMake\Target\DependencyResolver;
use PhpMake\Task\TaskExecutor;
use PhpMake\Task\TaskFactory;
use PhpMake\Utilities\Logger;

final class BuildSmokeTest extends TestCase
{
    private Logger $logger;

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
        $this->logger = new Logger(true, true, true);
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
    public function testBuildConfigurationGetters(): void
    {
        // Arrange
        $targets = [
            'default' => [
                'tasks'   => [],
                'depends' => [],
            ],
        ];
        $config = new BuildConfiguration(
            'My Project',
            'A sample project',
            '1.0.0',
            $targets,
            'default'
        );
        // Action/Assert
        $this->assertSame('My Project', $config->getName());
        $this->assertSame('A sample project', $config->getDescription());
        $this->assertSame('1.0.0', $config->getVersion());
        $this->assertSame($targets, $config->getTargets());
        $this->assertSame('default', $config->getDefaultTarget());
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testBuildValidatorValidConfig(): void
    {
        // Arrange
        $configArray = [
            'name'           => 'My Project',
            'description'    => 'Valid test project',
            'version'        => '1.0.0',
            'targets'        => [
                'default' => ['tasks' => []],
            ],
            'default_target' => 'default',
        ];
        // Action
        $validator = new BuildValidator();
        $errors = $validator->validate($configArray);
        // Assert
        $this->assertEmpty($errors, "Expected no errors for a valid build configuration.");
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testBuildValidatorInvalidConfig(): void
    {
        // Arrange
        $configArray = [
            // Intentionally missing 'name' and 'version'.
            'description'    => 'Test project',
            'targets'        => [],
            'default_target' => 'nonexistent',
        ];
        $validator = new BuildValidator();
        // Action
        $errors = $validator->validate($configArray);
        // Assert
        $this->assertNotEmpty($errors, "Expected errors for invalid build configuration.");
        $combinedErrors = implode(" ", $errors);
        $this->assertStringContainsString("Missing required field: name", $combinedErrors);
        $this->assertStringContainsString("Default target 'nonexistent' does not exist", $combinedErrors);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testDependencyResolverNoDependencies(): void
    {
        // Arrange
        $targets = [
            'default' => [
                'tasks' => [],
            ],
            'another' => [
                'tasks' => [],
            ],
        ];
        // Action
        $config = new BuildConfiguration('My Project', 'Test', '1.0.0', $targets, 'default');
        $resolver = new DependencyResolver();
        $order = $resolver->resolve($config, 'default');
        // Assert
        $this->assertIsArray($order);
        // When no dependencies, the order should contain just the target.
        $this->assertSame(['default'], $order);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testDependencyResolverWithDependencies(): void
    {
        // Arrange
        // Here, target "a" depends on "b".
        $targets = [
            'a' => [
                'tasks'   => [],
                'depends' => ['b'],
            ],
            'b' => [
                'tasks'   => [],
                'depends' => [],
            ],
        ];
        $config = new BuildConfiguration('My Project', 'Test', '1.0.0', $targets, 'a');
        $resolver = new DependencyResolver();
        // Action
        $order = $resolver->resolve($config, 'a');
        // Assert
        // Expected order: "b" comes before "a"
        $this->assertContains('b', $order);
        $this->assertContains('a', $order);
        $this->assertSame('b', $order[0]);
        $this->assertSame('a', end($order));
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testDependencyResolverCircularDependency(): void
    {
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Circular dependency detected");
        // Arrange
        // Create a circular dependency: a depends on b and b depends on a.
        $targets = [
            'a' => [
                'tasks'   => [],
                'depends' => ['b'],
            ],
            'b' => [
                'tasks'   => [],
                'depends' => ['a'],
            ],
        ];
        $config = new BuildConfiguration('My Project', 'Test', '1.0.0', $targets, 'a');
        $resolver = new DependencyResolver();
        // Action
        $resolver->resolve($config, 'a');
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testBuildExecutor(): void
    {
        // Arrange
        // Setup a build configuration with a single "echo" task.
        $targets = [
            'test' => [
                'tasks' => [
                    [
                        'type'   => 'echo',
                        'params' => ['message' => 'Hello BuildExecutor'],
                    ],
                ],
            ],
        ];
        $config = new BuildConfiguration(
            'Test Project',
            'Test project for BuildExecutor',
            '1.0.0',
            $targets,
            'test'
        );
        // Instantiate required objects.
        $taskFactory = new TaskFactory($this->logger);
        $dependencyResolver = new DependencyResolver();
        $taskExecutor = new TaskExecutor();
        $buildExecutor = new BuildExecutor($taskFactory, $dependencyResolver, $taskExecutor);
        // Action
        // Execute with target 'test' (which will run an echo task).
        $exitCode = $buildExecutor->execute($config, 'test', $this->logger);
        // Action
        $this->assertSame(0, $exitCode);
    }
}
