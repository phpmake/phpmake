<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Exercises --validate-build as a separate CLI process. */
final class CliValidationTest extends TestCase
{
    private string $workingDirectory;
    private string $cliScript;

    protected function setUp(): void
    {
        $this->workingDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'phpmake-cli-validation-'
            . bin2hex(random_bytes(8));
        mkdir($this->workingDirectory);
        $this->cliScript = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'phpmake.php';
    }

    protected function tearDown(): void
    {
        $buildFile = $this->workingDirectory . DIRECTORY_SEPARATOR . 'build.json';
        if (is_file($buildFile)) {
            unlink($buildFile);
        }
        if (is_dir($this->workingDirectory)) {
            rmdir($this->workingDirectory);
        }
    }

    /** Malformed JSON exits cleanly without an uncaught fatal error. */
    public function testValidateBuildReportsMalformedJsonCleanly(): void
    {
        file_put_contents($this->workingDirectory . DIRECTORY_SEPARATOR . 'build.json', '{"name":');

        $result = $this->runValidationCommand();

        self::assertSame(1, $result['exitCode']);
        self::assertNotSame('', trim($result['stderr']));
        self::assertStringNotContainsString('Fatal error', $result['stderr']);
        self::assertStringNotContainsString('Uncaught', $result['stderr']);
    }

    /** A JSON array at the root is rejected as an invalid build document. */
    public function testValidateBuildRejectsNonObjectRoot(): void
    {
        file_put_contents($this->workingDirectory . DIRECTORY_SEPARATOR . 'build.json', '[]');

        $result = $this->runValidationCommand();

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('must be a JSON object', $result['stderr']);
        self::assertStringNotContainsString('Fatal error', $result['stderr']);
    }

    /** Runs the real CLI entry point with an isolated build directory. */
    private function runValidationCommand(): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $this->cliScript, '--validate-build'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->workingDirectory,
            null,
            ['bypass_shell' => true]
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
}
