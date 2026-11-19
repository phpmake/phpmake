<?php

declare(strict_types=1);

namespace PhpMake\Tests\System;

use PhpMake\Config;
use PHPUnit\Framework\TestCase;

/** Verifies the installed command-line entry point as a real operating-system process. */
final class RuntimeSystemTest extends TestCase
{
    private string $projectRoot;

    private string $cliPath;

    private string $temporaryDirectory;

    /** Resolves CLI paths and creates an isolated project workspace. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 2);
        $this->cliPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'phpmake.php';
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-system-' . bin2hex(random_bytes(8));

        self::assertFileExists($this->cliPath);
        self::assertTrue(mkdir($this->temporaryDirectory, 0o777, true));
    }

    /** Removes the isolated workspace after each system test. */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    /** Confirms version output and a successful operating-system exit code. */
    public function testVersionCommandExitsSuccessfully(): void
    {
        // Arrange
        // Act
        $result = $this->runCli(['--version'], $this->temporaryDirectory);

        // Assert
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame(Config::TOOL_VERSION . PHP_EOL, $result['stdout']);
        self::assertSame('', $result['stderr']);
    }


    /** Ensures the release version file and runtime constant stay synchronized. */
    public function testVersionFileMatchesRuntimeConstant(): void
    {
        // Arrange
        $versionFile = $this->projectRoot . DIRECTORY_SEPARATOR . 'VERSION.txt';

        // Act
        $version = trim((string) file_get_contents($versionFile));

        // Assert
        self::assertSame(Config::TOOL_VERSION, $version);
    }

    /** Confirms the help command reports its usage and supported operational options. */
    public function testHelpCommandExitsSuccessfully(): void
    {
        // Arrange
        // Act
        $result = $this->runCli(['--help'], $this->temporaryDirectory);

        // Assert
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('Usage: phpmake', $result['stdout']);
        self::assertStringContainsString('--validate-build', $result['stdout']);
        self::assertStringContainsString('--diagnostics', $result['stdout']);
    }

    /** Confirms runtime diagnostics are produced by the real PHP process. */
    public function testDiagnosticsCommandReportsRuntimeInformation(): void
    {
        // Arrange
        // Act
        $result = $this->runCli(['--diagnostics'], $this->temporaryDirectory);

        // Assert
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('Diagnostics information:', $result['stdout']);
        self::assertStringContainsString('PHP:', $result['stdout']);
        self::assertStringContainsString('OS:', $result['stdout']);
        self::assertStringContainsString('Loaded extensions:', $result['stdout']);
    }

    /** Confirms --init creates a usable build file and rejects overwriting it. */
    public function testInitCreatesValidBuildFileAndRefusesOverwrite(): void
    {
        // Arrange
        // Act
        $first = $this->runCli(['--init'], $this->temporaryDirectory);

        // Assert
        self::assertSame(0, $first['exitCode'], $first['stderr']);
        self::assertFileExists($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'build.json');
        self::assertStringContainsString('created successfully', $first['stdout']);

        $validation = $this->runCli(['--validate-build', '--no-log'], $this->temporaryDirectory);
        self::assertSame(0, $validation['exitCode'], $validation['stderr']);
        self::assertStringContainsString('Build configuration is valid.', $validation['stdout']);

        $second = $this->runCli(['--init'], $this->temporaryDirectory);
        self::assertSame(1, $second['exitCode']);
        self::assertStringContainsString('already exists', $second['stderr']);
    }

    /** Confirms a missing build file and an unsupported flag produce non-zero exits. */
    public function testInvalidCliRequestsFailWithUsefulDiagnostics(): void
    {
        // Arrange
        // Act
        $missingBuild = $this->runCli(['--validate-build'], $this->temporaryDirectory);

        // Assert
        self::assertSame(1, $missingBuild['exitCode']);
        self::assertStringContainsString('not found or unreadable', $missingBuild['stderr']);

        $unknownOption = $this->runCli(['--unsupported'], $this->temporaryDirectory);
        self::assertSame(1, $unknownOption['exitCode']);
        self::assertStringContainsString('Unknown option', $unknownOption['stderr']);
    }

    /** Runs the real PHPMake entry point with isolated pipes and a selected working directory. */
    private function runCli(array $arguments, string $workingDirectory): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->cliPath, ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory
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

    /** Recursively removes an isolated workspace without traversing symbolic links. */
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
