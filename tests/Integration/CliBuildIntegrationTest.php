<?php

declare(strict_types=1);

namespace PhpMake\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Exercises the real PHPMake CLI and its complete build pipeline. */
final class CliBuildIntegrationTest extends TestCase
{
    private string $projectDirectory;

    private string $cliPath;

    /** Creates an isolated project directory for each CLI integration test. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'phpmake-integration-'
            . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($this->projectDirectory, 0777, true));
        $this->cliPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpmake.php';
        self::assertFileExists($this->cliPath);
    }

    /** Removes the isolated project and every artifact created by the CLI. */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDirectory);
        parent::tearDown();
    }

    /** Runs a dependency chain that copies files, executes PHP, and creates a ZIP archive. */
    public function testCliExecutesDependencyChainAndCreatesExpectedArchive(): void
    {
        // Arrange
        file_put_contents($this->projectDirectory . DIRECTORY_SEPARATOR . 'input.txt', 'source-content');

        // Act
        $this->writeBuildConfiguration([
            'prepare' => [
                'tasks' => [
                    ['type' => 'create_directory', 'params' => ['name' => 'build']],
                ],
            ],
            'compile' => [
                'depends' => ['prepare'],
                'tasks' => [
                    ['type' => 'copy', 'params' => ['source' => 'input.txt', 'dest' => 'build/copied.txt']],
                    [
                        'type' => 'exec',
                        'params' => [
                            'command' => PHP_BINARY,
                            'args' => ['-r', 'file_put_contents("build/generated.txt", "generated-content");'],
                        ],
                    ],
                ],
            ],
            'package' => [
                'depends' => ['compile'],
                'tasks' => [
                    ['type' => 'archive', 'params' => ['source' => 'build', 'output' => 'dist/package.zip']],
                ],
            ],
        ], 'package');

        $result = $this->runCli(['--silent', '--no-log']);

        // Assert
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertFileExists($this->projectDirectory . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'copied.txt');
        self::assertSame(
            'source-content',
            file_get_contents($this->projectDirectory . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'copied.txt')
        );
        self::assertFileExists($this->projectDirectory . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'generated.txt');

        $archive = new ZipArchive();
        self::assertTrue($archive->open($this->projectDirectory . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'package.zip') === true);
        try {
            self::assertSame('source-content', $archive->getFromName('copied.txt'));
            self::assertSame('generated-content', $archive->getFromName('generated.txt'));
        } finally {
            $archive->close();
        }
    }

    /** Validates a real build.json through the CLI validation option. */
    public function testCliValidatesBuildConfigurationWithoutExecutingTasks(): void
    {
        // Arrange
        // Act
        $this->writeBuildConfiguration([
            'default' => [
                'tasks' => [
                    ['type' => 'create_directory', 'params' => ['name' => 'must-not-exist']],
                ],
            ],
        ], 'default');

        $result = $this->runCli(['--validate-build', '--no-log']);

        // Assert
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('Build configuration is valid.', $result['stdout']);
        self::assertDirectoryDoesNotExist($this->projectDirectory . DIRECTORY_SEPARATOR . 'must-not-exist');
    }

    /** Returns a non-zero CLI exit code and stops subsequent tasks after a process failure. */
    public function testCliStopsBuildAfterExecTaskFails(): void
    {
        // Arrange
        // Act
        $this->writeBuildConfiguration([
            'default' => [
                'tasks' => [
                    [
                        'type' => 'exec',
                        'params' => ['command' => PHP_BINARY, 'args' => ['-r', 'exit(7);']],
                    ],
                    ['type' => 'create_directory', 'params' => ['name' => 'must-not-exist']],
                ],
            ],
        ], 'default');

        $result = $this->runCli(['--silent', '--no-log']);

        // Assert
        self::assertSame(1, $result['exitCode']);
        self::assertDirectoryDoesNotExist($this->projectDirectory . DIRECTORY_SEPARATOR . 'must-not-exist');
    }

    /** Rejects an invalid build configuration through the real CLI entry point. */
    public function testCliReturnsFailureForInvalidBuildConfiguration(): void
    {
        // Arrange
        // Act
        $this->writeBuildConfiguration([
            'default' => [
                'tasks' => [
                    ['type' => 'unknown_task', 'params' => (object) []],
                ],
            ],
        ], 'default');

        $result = $this->runCli(['--validate-build', '--no-log']);

        // Assert
        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString("Unknown task type 'unknown_task'", $result['stderr']);
    }

    /** Writes a valid top-level PHPMake configuration to the isolated project. */
    private function writeBuildConfiguration(array $targets, string $defaultTarget): void
    {
        $configuration = [
            'name' => 'Integration Test Project',
            'description' => 'Temporary project used by CLI integration tests.',
            'version' => '1.0.0',
            'targets' => $targets,
            'default_target' => $defaultTarget,
        ];

        file_put_contents(
            $this->projectDirectory . DIRECTORY_SEPARATOR . 'build.json',
            json_encode($configuration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
    }

    /** Executes the actual CLI script in the temporary project directory. */
    private function runCli(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->cliPath, ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->projectDirectory
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /** Recursively removes a test project without following symbolic links. */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                unlink($directory);
            }

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
