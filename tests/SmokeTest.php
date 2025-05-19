<?php

declare(strict_types=1);

namespace PhpMake\Tests {
    use PhpMake\Build\BuildConfiguration;
    use PhpMake\Build\BuildExecutor;
    use PhpMake\Build\BuildLoader;
    use PhpMake\Build\BuildValidator;
    use PhpMake\Cli\CliParser;
    use PhpMake\Target\DependencyResolver;
    use PhpMake\Task\TaskExecutor;
    use PhpMake\Task\TaskFactory;
    use PhpMake\Task\Types\CopyTask;
    use PhpMake\Utilities\Logger;
    use Throwable;
    use PHPUnit\Framework\TestCase;

    /**
     * @group Smoke
     */
    final class SmokeTest extends TestCase
    {
        private DependencyResolver $dependencyResolver;

        private TaskExecutor $taskExecutor;

        private Logger $logger;

        private BuildConfiguration $buildConfiguration;

        private BuildExecutor $buildExecutor;

        private BuildLoader $buildLoader;

        private BuildValidator $buildValidator;

        /**
         * This method is called before the first test method in the test class is executed.
         *
         * @doesNotPerformAssertions
         *
         * @return void
         */
        public static function setUpBeforeClass(): void {}

        /**
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
            $this->dependencyResolver = new DependencyResolver();
            $this->taskExecutor = new TaskExecutor();
            $this->logger = new Logger(true, true, true);
            $this->buildConfiguration = new BuildConfiguration('', '', '', [], '');
            $this->buildExecutor = new BuildExecutor(new TaskFactory($this->logger), $this->dependencyResolver, $this->taskExecutor);
            $this->buildLoader = new BuildLoader();
            $this->buildValidator = new BuildValidator();
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
        public function testComposerCanLoadLibraryClass(): void
        {
            // AAA
            $this->assertTrue(class_exists(DependencyResolver::class));
            $this->assertTrue(class_exists(TaskExecutor::class));
            $this->assertTrue(class_exists(Logger::class));
            $this->assertTrue(class_exists(BuildConfiguration::class));
            $this->assertTrue(class_exists(BuildLoader::class));
            $this->assertTrue(class_exists(BuildValidator::class));
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testDependencyResolverInstanceIsObject(): void
        {
            // AAA
            $this->assertNotNull($this->dependencyResolver);
            // AAA
            $this->assertIsObject($this->dependencyResolver);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testTaskExecutorInstanceIsObject(): void
        {
            // AAA
            $this->assertNotNull($this->taskExecutor);
            // AAA
            $this->assertIsObject($this->taskExecutor);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testLoggerInstanceIsObject(): void
        {
            // AAA
            $this->assertNotNull($this->logger);
            // AAA
            $this->assertIsObject($this->logger);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testBuildConfigurationInstanceIsObject(): void
        {
            // AAA
            $this->assertNotNull($this->buildConfiguration);
            // AAA
            $this->assertIsObject($this->buildConfiguration);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testBuildExecutorInstanceIsObject(): void
        {
            // AAA
            $this->assertNotNull($this->buildExecutor);
            // AAA
            $this->assertIsObject($this->buildExecutor);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testBuildLoaderInstanceIsObject(): void
        {
            // AAA
            $this->assertNotNull($this->buildLoader);
            // AAA
            $this->assertIsObject($this->buildLoader);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testBuildValidatorInstanceIsObject(): void
        {
            // AAA
            $this->assertNotNull($this->buildValidator);
            // AAA
            $this->assertIsObject($this->buildValidator);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testCliParserParse(): void
        {
            // Arrange
            $argv = ['bin/phpmake.php', '--debug', '--help', 'build'];
            $parser = new CliParser();
            // Action
            $result = $parser->parse($argv);
            // Assert
            $this->assertTrue($result['debug']);
            $this->assertTrue($result['help']);
            $this->assertEquals('build', $result['target']);
        }

        /**
         * @test
         *
         * @small
         *
         * @return void
         */
        public function testCopyTask(): void
        {
            // Arrange
            // Create a temporary source file.
            $sourceFile = tempnam(sys_get_temp_dir(), 'src_');
            file_put_contents($sourceFile, 'Test copy');
            // Create a destination path and ensure it does not exist.
            $destFile = tempnam(sys_get_temp_dir(), 'dest_');
            unlink($destFile);
            $copyTask = new CopyTask(['source' => $sourceFile, 'dest' => $destFile], $this->logger);
            // Action
            $result = $copyTask->execute(false, true);
            // Assert
            $this->assertTrue($result);
            $this->assertFileExists($destFile);
            $this->assertEquals('Test copy', file_get_contents($destFile));
            // Cleanup
            unlink($sourceFile);
            unlink($destFile);
        }
    }
}
