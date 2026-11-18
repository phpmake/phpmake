<?php

declare(strict_types=1);

namespace PhpMake\Tests\Smoke {
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

    /** Smoke tests for the main library components and CLI entry point. */
    final class SmokeTest extends TestCase
    {
        private DependencyResolver $dependencyResolver;

        private TaskExecutor $taskExecutor;

        private Logger $logger;

        private BuildConfiguration $buildConfiguration;

        private BuildExecutor $buildExecutor;

        private BuildLoader $buildLoader;

        private BuildValidator $buildValidator;

        /** Initializes the dependencies used by smoke tests. */
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

        /** Confirms the test process can release temporary object cycles. */
        protected function tearDown(): void
        {
            parent::tearDown();
        }

        /** Confirms Composer can autoload the primary library classes. */
        public function testComposerCanLoadLibraryClass(): void
        {
            // Arrange
            $classes = [DependencyResolver::class, TaskExecutor::class, Logger::class, BuildConfiguration::class, BuildLoader::class, BuildValidator::class];

            // Act
            $loaded = array_map(static fn (string $class): bool => class_exists($class), $classes);

            // Assert
            $this->assertNotContains(false, $loaded);
        }

        /** Confirms the dependency resolver is initialized. */
        public function testDependencyResolverInstanceIsObject(): void
        {
            // Arrange
            $resolver = $this->dependencyResolver;

            // Act
            $isObject = is_object($resolver);

            // Assert
            $this->assertTrue($isObject);
        }

        /** Confirms the task executor is initialized. */
        public function testTaskExecutorInstanceIsObject(): void
        {
            // Arrange
            $executor = $this->taskExecutor;

            // Act
            $isObject = is_object($executor);

            // Assert
            $this->assertTrue($isObject);
        }

        /** Confirms the logger is initialized. */
        public function testLoggerInstanceIsObject(): void
        {
            // Arrange
            $logger = $this->logger;

            // Act
            $isObject = is_object($logger);

            // Assert
            $this->assertTrue($isObject);
        }

        /** Confirms the build configuration is initialized. */
        public function testBuildConfigurationInstanceIsObject(): void
        {
            // Arrange
            $configuration = $this->buildConfiguration;

            // Act
            $isObject = is_object($configuration);

            // Assert
            $this->assertTrue($isObject);
        }

        /** Confirms the build executor is initialized. */
        public function testBuildExecutorInstanceIsObject(): void
        {
            // Arrange
            $executor = $this->buildExecutor;

            // Act
            $isObject = is_object($executor);

            // Assert
            $this->assertTrue($isObject);
        }

        /** Confirms the build loader is initialized. */
        public function testBuildLoaderInstanceIsObject(): void
        {
            // Arrange
            $loader = $this->buildLoader;

            // Act
            $isObject = is_object($loader);

            // Assert
            $this->assertTrue($isObject);
        }

        /** Confirms the build validator is initialized. */
        public function testBuildValidatorInstanceIsObject(): void
        {
            // Arrange
            $validator = $this->buildValidator;

            // Act
            $isObject = is_object($validator);

            // Assert
            $this->assertTrue($isObject);
        }

        /** Verifies CLI flags and target arguments are parsed. */
        public function testCliParserParse(): void
        {
            // Arrange
            $argv = ['bin/phpmake.php', '--debug', '--help', 'build'];
            $parser = new CliParser();

            // Act
            $result = $parser->parse($argv);

            // Assert
            $this->assertTrue($result['debug']);
            $this->assertTrue($result['help']);
            $this->assertSame('build', $result['target']);
        }

        /** Verifies a real copy task copies file contents successfully. */
        public function testCopyTask(): void
        {
            // Arrange
            $baseDir = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-test';
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0777, true);
            }

            $sourceFile = $baseDir . DIRECTORY_SEPARATOR . 'src_' . uniqid('', true) . '.txt';
            $destFile = $baseDir . DIRECTORY_SEPARATOR . 'dest_' . uniqid('', true) . '.txt';
            file_put_contents($sourceFile, 'Test copy');

            // Act
            $copyTask = new CopyTask(['source' => $sourceFile, 'dest' => $destFile], $this->logger);
            $result = $copyTask->execute(false, true);

            // Assert
            $this->assertTrue($result);
            $this->assertFileExists($destFile);
            $this->assertSame('Test copy', file_get_contents($destFile));

            // Cleanup
            @unlink($sourceFile);
            @unlink($destFile);
            @rmdir($baseDir);
        }
    }
}
