<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildLoader;
use PHPUnit\Framework\TestCase;

/** Tests loading and validating build configuration files. */
final class BuildLoaderTest extends TestCase
{
    private string $buildFile;

    protected function setUp(): void
    {
        $this->buildFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-build-' . bin2hex(random_bytes(8)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->buildFile)) {
            unlink($this->buildFile);
        }
    }

    /** Loads a complete, valid configuration and preserves its typed values. */
    public function testLoadValidBuildFile(): void
    {
        file_put_contents($this->buildFile, json_encode([
            'name' => 'TestProject',
            'description' => 'Test',
            'version' => '2.0.2',
            'targets' => ['test-target' => ['tasks' => [['type' => 'echo', 'params' => ['message' => 'Hello']]]]],
            'default_target' => 'test-target',
        ], JSON_THROW_ON_ERROR));

        $config = (new BuildLoader())->load($this->buildFile);

        self::assertSame('TestProject', $config->getName());
        self::assertSame('Test', $config->getDescription());
        self::assertSame('2.0.2', $config->getVersion());
        self::assertArrayHasKey('test-target', $config->getTargets());
        self::assertSame('test-target', $config->getDefaultTarget());
    }

    /** Rejects a JSON array instead of a configuration object. */
    public function testRejectsTopLevelJsonArray(): void
    {
        file_put_contents($this->buildFile, '[]');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a JSON object');
        (new BuildLoader())->load($this->buildFile);
    }

    /** Rejects incomplete configurations instead of silently inserting defaults. */
    public function testRejectsMissingRequiredFields(): void
    {
        file_put_contents($this->buildFile, '{"name":"Incomplete"}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: description');
        (new BuildLoader())->load($this->buildFile);
    }

    /** Rejects a syntactically valid JSON document with an invalid target definition. */
    public function testRejectsUnknownTaskType(): void
    {
        file_put_contents($this->buildFile, json_encode([
            'name' => 'Test',
            'description' => 'Invalid task',
            'version' => '1.0',
            'targets' => ['build' => ['tasks' => [['type' => 'not-real', 'params' => []]]]],
            'default_target' => 'build',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown task type 'not-real'");
        (new BuildLoader())->load($this->buildFile);
    }
}
