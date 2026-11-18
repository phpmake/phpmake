<?php

declare(strict_types=1);

namespace PhpMake\Tests\System {

    use PHPUnit\Framework\TestCase;

    /**
     * @group Requirements
     */
    final class RequirementsTest extends TestCase
    {
        /**
/** Tests is behavior. */
         * This method is called before the first test method in the test class is executed.
         *
         * @doesNotPerformAssertions
         *
         * @return void
         */
        public static function setUpBeforeClass(): void {}

        /**
/** Tests has behavior. */
         * This method is called after the last test method in the test class has been executed.
         *
         * @doesNotPerformAssertions
         *
         * @return void
         */
        public static function tearDownAfterClass(): void
        {
            gc_collect_cycles();
        }

        /**
         * This method is called BEFORE each test method.
         *
         * @doesNotPerformAssertions
         *
         * @return void
         */
        protected function setUp(): void
        {
            parent::setUp();
            // Methods initialization codes.
        }

        /**
         * This method is called AFTER each test method.
         *
         * @doesNotPerformAssertions
         *
         * @return void
         */
        protected function tearDown(): void
        {
            // Methods finalization codes.
            parent::tearDown();
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        final public function testProjectRequiredPhpVersion(): void
        {
            // Arrange
            $_result = (PHP_MAJOR_VERSION == 8) && (PHP_MINOR_VERSION >= 3);
            $this->assertTrue($_result);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        final public function testProjectRequiredPhpExtensions(): void
        {
            // Arrange
            $extensions = [
                'json',
                'zip',
            ];

            foreach ($extensions as $extension) {
                $this->assertTrue(extension_loaded($extension));
            }
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        final public function testProjectDirectoriesExist(): void
        {
            // Arrange
            $directories = [
                '.github',
                'bin',
                'resources',
                'schema',
                'src',
                'tests',
                'vendor',
            ];

            foreach ($directories as $directory) {
                $this->assertDirectoryExists($directory);
            }
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        final public function testProjectFilesExist(): void
        {
            // Arrange
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
                'CHANGELOG.txt',
                'CODE_OF_CONDUCT.txt',
                'composer.json',
                'CONTRIBUTING.txt',
                'CONTRIBUTORS.txt',
                'DCO.txt',
                'FAQ.txt',
                'LICENSE',
                'NOTICE.txt',
                'phpunit.xml',
                'README.md',
                'rector.php',
                'RELEASE-CHECKLIST.txt',
                'SECURITY.md',
                'TODO.txt',
                'VERSION.txt',
            ];

            foreach ($files as $file) {
                $this->assertFileExists($file);
            }
        }
    }
}
