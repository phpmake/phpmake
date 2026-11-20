<?php

declare(strict_types=1);

namespace PhpMake\\Tests\\Unit;

use PhpMake\\Build\\BuildConfiguration;
use PHPUnit\\Framework\\TestCase;

/** Tests the immutable build configuration value object. */
final class BuildConfigurationTest extends TestCase
{
    /** Returns every constructor value through its corresponding accessor. */
    public function testGettersReturnConfiguredValues(): void
    {
        // Arrange
        $targets = [
            'default' => [
                'tasks' => [],
                'depends' => [],
            ],
        ];
        $configuration = new BuildConfiguration(
            'Project',
            'Build configuration test',
            '1.2.3',
            $targets,
            'default'
        );

        // Act
        $name = $configuration->getName();
        $description = $configuration->getDescription();
        $version = $configuration->getVersion();
        $actualTargets = $configuration->getTargets();
        $defaultTarget = $configuration->getDefaultTarget();

        // Assert
        self::assertSame('Project', $name);
        self::assertSame('Build configuration test', $description);
        self::assertSame('1.2.3', $version);
        self::assertSame($targets, $actualTargets);
        self::assertSame('default', $defaultTarget);
    }
}
