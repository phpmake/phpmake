<?php

declare(strict_types=1);

namespace PhpMake\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Verifies the Composer-installed binary entry point. */
final class ComposerBinTest extends TestCase
{
    /** Runs the package binary with the consuming project's Composer autoloader. */
    public function testComposerInstalledBinaryUsesConsumerAutoloader(): void
    {
        $projectRoot = dirname(__DIR__);
        $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-composer-bin-' . bin2hex(random_bytes(8));
        $autoloadDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'vendor';
        $packageBinDirectory = $autoloadDirectory . DIRECTORY_SEPARATOR . 'phpmake' . DIRECTORY_SEPARATOR . 'phpmake' . DIRECTORY_SEPARATOR . 'bin';

        self::assertTrue(mkdir($packageBinDirectory, 0777, true));

        $autoload = <<<'PHP'
<?php

namespace Composer\Autoload {
    class ClassLoader {}
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'PhpMake\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $sourceDirectory = __PROJECT_SOURCE__;
        $relativeClass = substr($class, strlen($prefix));
        $file = $sourceDirectory . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}
PHP;
        $autoload = str_replace('__PROJECT_SOURCE__', var_export($projectRoot . DIRECTORY_SEPARATOR . 'src', true), $autoload);

        $runner = <<<'PHP'
<?php

require __DIR__ . '/vendor/autoload.php';
$argv = [__FILE__, '--version'];
include __DIR__ . '/vendor/phpmake/phpmake/bin/phpmake.php';
PHP;

        try {
            self::assertNotFalse(file_put_contents($autoloadDirectory . DIRECTORY_SEPARATOR . 'autoload.php', $autoload));
            self::assertTrue(copy($projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpmake.php', $packageBinDirectory . DIRECTORY_SEPARATOR . 'phpmake.php'));
            self::assertNotFalse(file_put_contents($temporaryRoot . DIRECTORY_SEPARATOR . 'run.php', $runner));

            $process = proc_open(
                [PHP_BINARY, $temporaryRoot . DIRECTORY_SEPARATOR . 'run.php'],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $temporaryRoot
            );

            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame(0, $exitCode, $stderr);
            self::assertSame(\PhpMake\Config::TOOL_VERSION . PHP_EOL, $stdout);
        } finally {
            @unlink($temporaryRoot . DIRECTORY_SEPARATOR . 'run.php');
            @unlink($packageBinDirectory . DIRECTORY_SEPARATOR . 'phpmake.php');
            @unlink($autoloadDirectory . DIRECTORY_SEPARATOR . 'autoload.php');
            @rmdir($packageBinDirectory);
            @rmdir(dirname($packageBinDirectory));
            @rmdir(dirname(dirname($packageBinDirectory)));
            @rmdir($autoloadDirectory);
            @rmdir($temporaryRoot);
        }
    }
}
