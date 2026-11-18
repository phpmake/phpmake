<?php

declare(strict_types=1);

namespace PhpMake\Tests\System {

    use PHPUnit\Framework\TestCase;

    /** Verifies runtime requirements and the expected repository layout. */
    final class RequirementsTest extends TestCase
    {
        /** Checks that the runtime meets the minimum supported PHP version. */
        public function testProjectRequiredPhpVersion(): void
        {
            self::assertTrue(
                version_compare(PHP_VERSION, '8.3.0', '>='),
                sprintf('PHP 8.3.0 or newer is required; %s is running.', PHP_VERSION)
            );
        }

        /** Checks that required PHP extensions are loaded. */
        public function testProjectRequiredPhpExtensions(): void
        {
            foreach (['json', 'zip'] as $extension) {
                self::assertTrue(extension_loaded($extension), sprintf('Required extension "%s" is not loaded.', $extension));
            }
        }

        /** Checks tracked and generated project directories using absolute paths. */
        public function testProjectDirectoriesExist(): void
        {
            $projectRoot = dirname(__DIR__, 2);
            foreach ([
                '.github',
                'bin',
                'documents',
                'resources',
                'schema',
                'src',
                'tests',
                'vendor',
            ] as $directory) {
                self::assertDirectoryExists(
                    $projectRoot . DIRECTORY_SEPARATOR . $directory,
                    sprintf('Required project directory "%s" is missing.', $directory)
                );
            }
        }

        /** Checks repository files at their actual tracked locations. */
        public function testProjectFilesExist(): void
        {
            $projectRoot = dirname(__DIR__, 2);
            $files = [
                '.editorconfig',
                '.gitattributes',
                '.gitignore',
                '.mailmap',
                '.php-cs-fixer.php',
                '.phplint.yml',
                '.styleci.yml',
                'build.json',
                'build.json.example',
                'composer.json',
                'documents/CHANGELOG.txt',
                'documents/CODE_OF_CONDUCT.txt',
                'documents/CONTRIBUTING.txt',
                'documents/CONTRIBUTORS.txt',
                'documents/DCO.txt',
                'documents/FAQ.txt',
                'documents/NOTICE.txt',
                'documents/RELEASE-CHECKLIST.txt',
                'documents/TODO.txt',
                'LICENSE',
                'phpunit.xml',
                'README.md',
                'rector.php',
                'SECURITY.md',
                'VERSION.txt',
            ];

            foreach ($files as $file) {
                self::assertFileExists(
                    $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file),
                    sprintf('Required project file "%s" is missing.', $file)
                );
            }
        }
    }
}