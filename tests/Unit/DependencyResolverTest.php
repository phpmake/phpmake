<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildConfiguration;
use PhpMake\Target\DependencyResolver;
use PHPUnit\Framework\TestCase;

/** Tests dependency ordering and invalid dependency handling. */
final class DependencyResolverTest extends TestCase
{
    /**
     * This method is called before the first test method in the test class is executed.
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public static function setUpBeforeClass(): void {}

    /**
     * This method is called after the last test method in the test class has been executed.
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public static function tearDownAfterClass(): void
    {
        gc_collect_cycles();
    }

    /**
     * This method is called BEFORE each test method.
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    protected function setUp(): void
    {
        parent::setUp();
        // Methods initialization codes.
    }

    /**
     * This method is called AFTER each test method.
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    protected function tearDown(): void
    {
        // Methods finalization codes.
        parent::tearDown();
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testDependencyOrder(): void
    {
        // Arrange
        $config = new BuildConfiguration(
            'Test',
            'Test',
            '1.0',
            [
                'target_a' => ['depends' => [], 'tasks' => []],
                'target_b' => ['depends' => ['target_a'], 'tasks' => []],
                'target_c' => ['depends' => ['target_b'], 'tasks' => []],
            ],
            'target_c'
        );
        $resolver = new DependencyResolver();
        // Action
        $order = $resolver->resolve($config, 'target_c');
        // Assert
        $this->assertEquals(['target_a', 'target_b', 'target_c'], $order);
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testCircularDependency(): void
    {
        // Assert
        $this->expectException(\Exception::class);
        // Arrange
        $config = new BuildConfiguration(
            'Test',
            'Test',
            '1.0',
            [
                'a' => ['depends' => ['b']],
                'b' => ['depends' => ['a']],
            ],
            'a'
        );
        $resolver = new DependencyResolver();
        // Action
        $resolver->resolve($config, 'a');
    }

    /** Rejects a target that depends on an undefined target. */
    public function testMissingDependencyIsRejected(): void
    {
        // Arrange
        $config = new BuildConfiguration(
            'Test',
            'Test',
            '1.0',
            ['build' => ['depends' => ['missing'], 'tasks' => []]],
            'build'
        );
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        // Act
        (new DependencyResolver())->resolve($config, 'build');
    }
}
