<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildLoader;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

final class BuildLoaderTest extends TestCase
{
    private string $buildFile = __DIR__ . '/build.json';

    public function setUp(): void
    {
        // Create a valid build.json
        file_put_contents(
            $this->buildFile,
            <<<JSON
                {
                  "name": "TestProject",
                  "description": "Test",
                  "version": "2.0.1",
                  "targets": {
                    "test_target": {
                      "tasks": [
                        { "type": "echo", "params": { "message": "Hello" } }
                      ]
                    }
                  },
                  "default_target": "test_target"
                }
                JSON
        );
    }

    public function tearDown(): void
    {
        unlink($this->buildFile);
    }

    public function testLoadValidBuildFile(): void
    {
        $buildLoader = new BuildLoader();
        $config = $buildLoader->load($this->buildFile);
        $this->assertNotEmpty($config);
    }
}
