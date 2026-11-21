<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildConfiguration;
use PhpMake\Target\DependencyResolver;
use PHPUnit\Framework\TestCase;

/** Tests dependency ordering and invalid dependency handling. */
final class DependencyResolverTest extends TestCase
{
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

    public function testCircularDependency(): void
    {
        // Assert
        $this->expectException(\RuntimeException::class);
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

    /** Resolves duplicate dependencies only once while preserving their first-seen order. */
    public function testDuplicateDependenciesAreResolvedOnce(): void
    {
        // Arrange
        $config = new BuildConfiguration(
            'Test',
            'Duplicate dependencies',
            '1.0',
            [
                'base' => ['depends' => [], 'tasks' => []],
                'build' => ['depends' => ['base', 'base'], 'tasks' => []],
            ],
            'build'
        );

        // Act
        $order = (new DependencyResolver())->resolve($config, 'build');

        // Assert
        self::assertSame(['base', 'build'], $order);
    }

    /** Rejects a dependency property that is not an array. */
    public function testNonArrayDependenciesAreRejected(): void
    {
        // Arrange
        $config = new BuildConfiguration(
            'Test',
            'Malformed dependencies',
            '1.0',
            ['build' => ['depends' => 'base', 'tasks' => []]],
            'build'
        );

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dependencies for target');

        // Act
        (new DependencyResolver())->resolve($config, 'build');
    }

    /** Rejects dependency entries that are not non-empty strings. */
    public function testNonStringDependencyIsRejected(): void
    {
        // Arrange
        $config = new BuildConfiguration(
            'Test',
            'Invalid dependency entry',
            '1.0',
            ['build' => ['depends' => [123], 'tasks' => []]],
            'build'
        );

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid dependency');

        // Act
        (new DependencyResolver())->resolve($config, 'build');
    }

}
