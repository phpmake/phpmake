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
            // Arrange
            $minimumVersion = '8.0.0';

            // Act
            $supported = version_compare(PHP_VERSION, $minimumVersion, '>=');

            // Assert
            self::assertTrue(
                $supported,
                sprintf('PHP 8.0.0 or newer is required; %s is running.', PHP_VERSION)
            );
        }

        /** Checks that required PHP extensions are loaded. */
        public function testProjectRequiredPhpExtensions(): void
        {
            // Arrange
            $requiredExtensions = ['ctype', 'json', 'zip'];

            // Act
            $loadedExtensions = array_filter($requiredExtensions, 'extension_loaded');

            // Assert
            self::assertSame(
                [],
                array_values(array_diff($requiredExtensions, $loadedExtensions)),
                'One or more required PHP extensions are not loaded.'
            );
        }

        /** Checks tracked and generated project directories using absolute paths. */
        public function testProjectDirectoriesExist(): void
        {
            // Arrange
            $projectRoot = dirname(__DIR__, 2);
            $directories = [
                '.github',
                'bin',
                'documents',
                'resources',
                'schema',
                'src',
                'tests',
                'vendor',
            ];

            // Act
            $missingDirectories = array_filter($directories, static fn(string $directory): bool => !is_dir(
                $projectRoot . DIRECTORY_SEPARATOR . $directory
            ));

            // Assert
            foreach ($missingDirectories as $directory) {
                self::assertDirectoryExists(
                    $projectRoot . DIRECTORY_SEPARATOR . $directory,
                    sprintf('Required project directory "%s" is missing.', $directory)
                );
            }
        }

        /** Checks repository files at their actual tracked locations. */
        public function testProjectFilesExist(): void
        {
            // Arrange
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

            // Act
            $missingFiles = array_filter($files, static fn(string $file): bool => !is_file(
                $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file)
            ));

            // Assert
            foreach ($missingFiles as $file) {
                self::assertFileExists(
                    $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file),
                    sprintf('Required project file "%s" is missing.', $file)
                );
            }
        }
    }
}
