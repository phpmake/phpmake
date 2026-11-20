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
}
