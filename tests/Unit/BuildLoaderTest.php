<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildLoader;
use PHPUnit\Framework\TestCase;

/** Tests loading and validating build configuration files. */
final class BuildLoaderTest extends TestCase
{
    private string $buildFile;

    /** Initializes test fixtures. */
    protected function setUp(): void
    {
        $this->buildFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-build-' . bin2hex(random_bytes(8)) . '.json';
    }

    /** Cleans up test fixtures. */
    protected function tearDown(): void
    {
        if (is_file($this->buildFile)) {
            unlink($this->buildFile);
        }
    }

    /** Loads a complete, valid configuration and preserves its typed values. */
    public function testLoadValidBuildFile(): void
    {
        // Arrange
        file_put_contents($this->buildFile, json_encode([
            'name' => 'TestProject',
            'description' => 'Test',
            'version' => '2.0.2',
            'targets' => ['test-target' => ['tasks' => [['type' => 'echo', 'params' => ['message' => 'Hello']]]]],
            'default_target' => 'test-target',
        ], JSON_THROW_ON_ERROR));

        // Act
        $config = (new BuildLoader())->load($this->buildFile);

        // Assert
        self::assertSame('TestProject', $config->getName());
        self::assertSame('Test', $config->getDescription());
        self::assertSame('2.0.2', $config->getVersion());
        self::assertArrayHasKey('test-target', $config->getTargets());
        self::assertSame('test-target', $config->getDefaultTarget());
    }

    /** Rejects a JSON array instead of a configuration object. */
    public function testRejectsTopLevelJsonArray(): void
    {
        // Arrange
        file_put_contents($this->buildFile, '[]');

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a JSON object');
        // Act
        (new BuildLoader())->load($this->buildFile);
    }

    /** Rejects incomplete configurations instead of silently inserting defaults. */
    public function testRejectsMissingRequiredFields(): void
    {
        // Arrange
        file_put_contents($this->buildFile, '{"name":"Incomplete"}');

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: description');
        // Act
        (new BuildLoader())->load($this->buildFile);
    }

    /** Rejects a syntactically valid JSON document with an invalid target definition. */
    public function testRejectsUnknownTaskType(): void
    {
        // Arrange
        file_put_contents($this->buildFile, json_encode([
            'name' => 'Test',
            'description' => 'Invalid task',
            'version' => '1.0',
            'targets' => ['build' => ['tasks' => [['type' => 'not-real', 'params' => (object) []]]]],
            'default_target' => 'build',
        ], JSON_THROW_ON_ERROR));

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown task type 'not-real'");
        // Act
        (new BuildLoader())->load($this->buildFile);
    }

    /** Malformed JSON is rejected with a JSON decoding exception. */
    public function testRejectsMalformedJson(): void
    {
        // Arrange
        file_put_contents($this->buildFile, '{"name":');

        // Assert
        $this->expectException(\JsonException::class);
        // Act
        (new BuildLoader())->load($this->buildFile);
    }

    /** Rejects targets encoded as a JSON array instead of an object. */
    public function testRejectsTargetsJsonArray(): void
    {
        // Arrange
        file_put_contents($this->buildFile, '{"name":"Test","description":"Test","version":"1","targets":[],"default_target":"build"}');

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Field 'targets' must be a JSON object.");
        // Act
        (new BuildLoader())->load($this->buildFile);
    }

    /** Rejects task definitions whose JSON object/array shapes do not match the schema. */
    public function testRejectsMalformedJsonTaskShapes(): void
    {
        // Arrange
        file_put_contents($this->buildFile, '{"name":"Test","description":"Test","version":"1","targets":{"build":{"tasks":{}}},"default_target":"build"}');

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Tasks for target 'build' must be a JSON array.");
        // Act
        (new BuildLoader())->load($this->buildFile);
    }


    /** Rejects exec args encoded as a JSON object rather than an array. */
    public function testRejectsExecArgsJsonObject(): void
    {
        // Arrange
        file_put_contents($this->buildFile, '{"name":"Test","description":"Test","version":"1","targets":{"build":{"tasks":[{"type":"exec","params":{"command":"php","args":{"0":"--version"}}}]}},"default_target":"build"}');

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a JSON array');
        // Act
        (new BuildLoader())->load($this->buildFile);
    }


    /** Rejects malformed nested JSON object/array shapes before runtime validation. */
    public function testRejectsMalformedNestedJsonShapes(): void
    {
        // Arrange
        $cases = [
            [
                '{"name":"Test","description":"Test","version":"1","targets":{"build":[]},"default_target":"build"}',
                "Target 'build' must be a JSON object.",
            ],
            [
                '{"name":"Test","description":"Test","version":"1","targets":{"build":{"depends":{},"tasks":[]}},"default_target":"build"}',
                "Dependencies for target 'build' must be a JSON array.",
            ],
            [
                '{"name":"Test","description":"Test","version":"1","targets":{"build":{"tasks":[{"type":"echo","params":[]} ]}},"default_target":"build"}',
                "Parameters for task 0 in target 'build' must be a JSON object.",
            ],
            [
                '{"name":"Test","description":"Test","version":"1","targets":{"build":{"tasks":["not-an-object"]}},"default_target":"build"}',
                "Task 0 in target 'build' must be a JSON object.",
            ],
        ];

        foreach ($cases as [$json, $expectedMessage]) {
            file_put_contents($this->buildFile, $json);

            // Act
            try {
                (new BuildLoader())->load($this->buildFile);
                self::fail('Malformed nested JSON shape should be rejected.');
            } catch (\InvalidArgumentException $exception) {
                // Assert
                self::assertStringContainsString($expectedMessage, $exception->getMessage());
            }
        }
    }

    /** Rejects oversized build files without reading them fully into memory. */
    public function testRejectsBuildFileAboveSizeLimit(): void
    {
        // Arrange
        file_put_contents($this->buildFile, str_repeat(' ', 2097153));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the maximum size');
        // Act
        (new BuildLoader())->load($this->buildFile);
    }

    /** Rejects a missing build file before attempting to read it. */
    public function testRejectsMissingBuildFile(): void
    {
        // Arrange
        self::assertFileDoesNotExist($this->buildFile);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found or unreadable');

        // Act
        (new BuildLoader())->load($this->buildFile);
    }

}
