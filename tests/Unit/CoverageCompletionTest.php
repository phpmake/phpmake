<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use FilesystemIterator;
use InvalidArgumentException;
use RuntimeException;
use PhpMake\Build\BuildConfiguration;
use PhpMake\Build\BuildLoader;
use PhpMake\Build\BuildValidator;
use PhpMake\Cli\CliParser;
use PhpMake\Target\DependencyResolver;
use PhpMake\Task\TaskExecutor;
use PhpMake\Task\TaskFactory;
use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/** Exercises production code paths that were missing from the original unit suite. */
final class CoverageCompletionTest extends TestCase
{
    private string $originalDirectory;

    private string $temporaryDirectory;

    /** Creates an isolated working directory for filesystem operations. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDirectory = getcwd();
        $this->temporaryDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'phpmake-unit-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($this->temporaryDirectory, 0777, true));
        self::assertTrue(chdir($this->temporaryDirectory));
    }

    /** Restores the working directory and removes all temporary test data. */
    protected function tearDown(): void
    {
        chdir($this->originalDirectory);
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    /** Covers BuildLoader success and validation failures for distinct JSON input shapes. */
    public function testBuildLoaderLoadsAndRejectsBuildFiles(): void
    {
        $valid = [
            'name' => 'Loader Test',
            'description' => 'Valid configuration',
            'version' => '1.2.3',
            'targets' => ['default' => ['tasks' => [['type' => 'echo', 'params' => ['message' => 'hello']]]]],
            'default_target' => 'default',
        ];
        file_put_contents('valid.json', json_encode($valid, JSON_THROW_ON_ERROR));

        $loaded = (new BuildLoader())->load('valid.json');
        self::assertSame('Loader Test', $loaded->getName());
        self::assertSame('Valid configuration', $loaded->getDescription());
        self::assertSame('1.2.3', $loaded->getVersion());
        self::assertSame('default', $loaded->getDefaultTarget());

        $this->assertThrows(RuntimeException::class, static fn() => (new BuildLoader())->load('missing.json'));
        file_put_contents('invalid-json.json', '{');
        $this->assertThrows(\JsonException::class, static fn() => (new BuildLoader())->load('invalid-json.json'));
        file_put_contents('array.json', '[]');
        $this->assertThrows(InvalidArgumentException::class, static fn() => (new BuildLoader())->load('array.json'));
        file_put_contents('invalid-schema.json', '{}');
        $this->assertThrows(InvalidArgumentException::class, static fn() => (new BuildLoader())->load('invalid-schema.json'));

        if (!in_array('phpmake-failing-read', stream_get_wrappers(), true)) {
            self::assertTrue(stream_wrapper_register('phpmake-failing-read', FailingReadStreamWrapper::class));
        }
        try {
            $this->assertThrows(
                RuntimeException::class,
                static fn() => (new BuildLoader())->load('phpmake-failing-read://build.json'),
                'Cannot read build file'
            );
        } finally {
            stream_wrapper_unregister('phpmake-failing-read');
        }
    }

    /** Covers malformed top-level fields, target structures, dependencies, and task parameters. */
    public function testBuildValidatorRejectsMalformedConfiguration(): void
    {
        $validator = new BuildValidator();
        self::assertNotEmpty($validator->validate([]));
        self::assertNotEmpty($validator->validate([
            'name' => ' ',
            'description' => false,
            'version' => null,
            'targets' => 'invalid',
            'default_target' => [],
        ]));

        $errors = $validator->validate([
            'name' => 'Validator',
            'description' => 'Cases',
            'version' => '1.0',
            'default_target' => 'missing',
            'targets' => [
                'scalar' => 'not-an-object',
                'dependency-type' => ['depends' => 'invalid', 'tasks' => []],
                'missing-deps' => ['depends' => [null, 'absent'], 'tasks' => 'invalid'],
                'missing-tasks' => [],
                'malformed-tasks' => [
                    'tasks' => [
                        null,
                        [],
                        ['type' => 'echo', 'params' => 'invalid'],
                        ['type' => 'unknown', 'params' => []],
                        ['type' => 'delete', 'params' => []],
                        ['type' => 'create_directory', 'params' => []],
                        ['type' => 'copy', 'params' => []],
                        ['type' => 'exec', 'params' => []],
                        ['type' => 'echo', 'params' => []],
                        ['type' => 'archive', 'params' => []],
                        ['type' => 'exec', 'params' => [
                            'command' => 'php',
                            'args' => ['ok', new \stdClass()],
                            'timeout' => 0,
                        ]],
                        ['type' => 'exec', 'params' => [
                            'command' => 'php',
                            'args' => 'invalid',
                            'timeout' => 'invalid',
                        ]],
                        ['type' => 'exec', 'params' => ['command' => 'php', 'timeout' => 86401]],
                    ],
                ],
            ],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Unknown task type', implode("\n", $errors));
        self::assertStringContainsString('requires non-empty string', implode("\n", $errors));
        self::assertStringContainsString('invalid \'timeout\'', implode("\n", $errors));
    }

    /** Covers command-line aliases, target selection, unknown flags, and extra arguments. */
    public function testCliParserHandlesAliasesAndRejectsInvalidArguments(): void
    {
        $parser = new CliParser();
        $result = $parser->parse([
            'phpmake.php', '-h', '--version', '--diagnostics', '-vb',
            '-d', '--silent', '-nl', '--init', 'release',
        ]);

        self::assertTrue($result['help']);
        self::assertTrue($result['showVersion']);
        self::assertTrue($result['diagnostics']);
        self::assertTrue($result['validateBuild']);
        self::assertTrue($result['debug']);
        self::assertTrue($result['silent']);
        self::assertTrue($result['noLog']);
        self::assertTrue($result['init']);
        self::assertSame('release', $result['target']);

        $this->assertThrows(\Exception::class, static fn() => $parser->parse(['phpmake.php', '--unknown']), 'Unknown option');
        $this->assertThrows(\Exception::class, static fn() => $parser->parse(['phpmake.php', 'release', 'package']), 'Extra argument');
    }

    /** Covers dependency ordering, de-duplication, cycle detection, and invalid graph nodes. */
    public function testDependencyResolverHandlesValidAndInvalidGraphs(): void
    {
        $resolver = new DependencyResolver();
        $valid = new BuildConfiguration('Graph', '', '', [
            'prepare' => ['depends' => [], 'tasks' => []],
            'compile' => ['depends' => ['prepare'], 'tasks' => []],
            'package' => ['depends' => ['prepare', 'compile', 'prepare'], 'tasks' => []],
        ], 'package');
        self::assertSame(['prepare', 'compile', 'package'], $resolver->resolve($valid, 'package'));

        $cycle = new BuildConfiguration('Cycle', '', '', [
            'first' => ['depends' => ['second'], 'tasks' => []],
            'second' => ['depends' => ['first'], 'tasks' => []],
        ], 'first');
        $this->assertThrows(\Exception::class, static fn() => $resolver->resolve($cycle, 'first'), 'Circular dependency');

        $single = new BuildConfiguration('Single', '', '', [
            'default' => ['depends' => [], 'tasks' => []],
        ], 'default');
        $this->assertThrows(InvalidArgumentException::class, static fn() => $resolver->resolve($single, 'unknown'), 'does not exist');

        $badDependencies = new BuildConfiguration('Bad', '', '', [
            'default' => ['depends' => 'invalid', 'tasks' => []],
        ], 'default');
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn() => $resolver->resolve($badDependencies, 'default'),
            'must be an array'
        );
    }

    /** Covers successful execution, invalid target entries, task factory errors, and task failures. */
    public function testTaskExecutorRunsTasksAndStopsOnEveryFailureType(): void
    {
        $factory = new TaskFactory($this->logger());
        $executor = new TaskExecutor();
        $valid = new BuildConfiguration('Executor', '', '', [
            'default' => ['tasks' => [['type' => 'echo', 'params' => ['message' => 'success']]]],
        ], 'default');

        self::assertSame(0, $executor->executeTasks($valid, ['default'], $factory, false, true));
        self::assertSame(1, $executor->executeTasks($valid, [null], $factory, false, true));
        self::assertSame(1, $executor->executeTasks($valid, ['missing-target'], $factory, false, true));
        $this->assertExecutorFails($executor, $factory, ['default' => 'not-an-array'], ['default']);

        $this->assertExecutorFails($executor, $factory, [
            'default' => [],
        ], ['default']);
        $this->assertExecutorFails($executor, $factory, [
            'default' => ['tasks' => [null]],
        ], ['default']);
        $this->assertExecutorFails($executor, $factory, [
            'default' => ['tasks' => [['type' => 'unknown', 'params' => []]]],
        ], ['default']);
        $this->assertExecutorFails($executor, $factory, [
            'default' => ['tasks' => [[
                'type' => 'exec',
                'params' => ['command' => PHP_BINARY, 'args' => ['-r', 'exit(8);']],
            ]]],
        ], ['default']);

        ob_start();
        try {
            self::assertSame(0, $executor->executeTasks($valid, ['default'], $factory, false, false));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertIsString($output);
        self::assertStringContainsString('Executing target: default', $output);
        self::assertStringContainsString('Task succeeded', $output);
    }

    /** Covers every task-factory mapping and malformed task configuration. */
    public function testTaskFactoryCreatesEverySupportedTask(): void
    {
        file_put_contents('source.txt', 'factory-source');
        $factory = new TaskFactory($this->logger());
        $cases = [
            ['delete', ['path' => 'missing.txt']],
            ['create_directory', ['name' => 'factory-directory']],
            ['copy', ['source' => 'source.txt', 'dest' => 'factory-copy.txt']],
            ['exec', ['command' => PHP_BINARY, 'args' => ['-v']]],
            ['echo', ['message' => 'factory']],
            ['archive', ['source' => 'source.txt', 'output' => 'factory.zip']],
        ];

        foreach ($cases as [$type, $params]) {
            self::assertSame($type, $factory->create(['type' => $type, 'params' => $params])->getType());
        }

        $this->assertThrows(\InvalidArgumentException::class, static fn() => $factory->create([]), 'non-empty string');
        $this->assertThrows(
            \InvalidArgumentException::class,
            static fn() => $factory->create(['type' => 'echo', 'params' => 'invalid']),
            'must be an array'
        );
        $this->assertThrows(\Exception::class, static fn() => $factory->create(['type' => 'unknown']), 'Unknown task type');
    }

    /** Covers file and recursive directory copies, error propagation, and symlink rejection. */
    public function testCopyTaskCopiesAndRejectsUnsafeInputs(): void
    {
        file_put_contents('source.txt', 'file-content');
        $fileTask = new CopyTask(['source' => 'source.txt', 'dest' => 'nested/copied.txt'], $this->logger());
        self::assertSame('copy', $fileTask->getType());
        self::assertTrue($fileTask->execute(false, true));
        self::assertSame('file-content', file_get_contents('nested/copied.txt'));

        mkdir('tree/child', 0777, true);
        file_put_contents('tree/root.txt', 'root');
        file_put_contents('tree/child/nested.txt', 'nested');
        self::assertTrue((new CopyTask(['source' => 'tree', 'dest' => 'tree-copy'], $this->logger()))->execute(false, true));
        self::assertSame('nested', file_get_contents('tree-copy/child/nested.txt'));

        self::assertFalse((new CopyTask(['source' => 'missing', 'dest' => 'missing-copy'], $this->logger()))->execute(false, true));

        if (@symlink('source.txt', 'source-link')) {
            self::assertFalse((new CopyTask(['source' => 'source-link', 'dest' => 'linked-copy'], $this->logger()))->execute(false, true));
            self::assertTrue(symlink('source.txt', 'tree/child/linked-file'));
            self::assertFalse((new CopyTask(['source' => 'tree', 'dest' => 'tree-with-link'], $this->logger()))->execute(false, true));
        }

        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => new CopyTask(['source' => '../outside', 'dest' => 'inside'], $this->logger()),
            'escapes project directory'
        );
    }

    /** Covers directory creation success, existing directories, and filesystem failure. */
    public function testCreateDirectoryTaskHandlesSuccessAndFailure(): void
    {
        $task = new CreateDirectoryTask(['name' => 'new/nested'], $this->logger());
        self::assertSame('create_directory', $task->getType());
        self::assertTrue($task->execute(false, true));
        self::assertTrue((new CreateDirectoryTask(['name' => 'new/nested'], $this->logger()))->execute(false, true));

        file_put_contents('file-blocker', 'file');
        self::assertFalse((new CreateDirectoryTask(['name' => 'file-blocker/child'], $this->logger()))->execute(false, true));

        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => new CreateDirectoryTask(['name' => '../outside'], $this->logger()),
            'escapes project directory'
        );
    }

    /** Covers delete success for absent, file, directory, and symlink paths. */
    public function testDeleteTaskHandlesFilesDirectoriesAndSymlinks(): void
    {
        self::assertSame('delete', (new DeleteTask(['path' => 'missing'], $this->logger()))->getType());
        self::assertTrue((new DeleteTask(['path' => 'missing'], $this->logger()))->execute(false, true));

        file_put_contents('delete.txt', 'delete');
        self::assertTrue((new DeleteTask(['path' => 'delete.txt'], $this->logger()))->execute(false, true));
        self::assertFileDoesNotExist('delete.txt');

        mkdir('delete-tree/child', 0777, true);
        file_put_contents('delete-tree/child/file.txt', 'nested');
        self::assertTrue((new DeleteTask(['path' => 'delete-tree'], $this->logger()))->execute(false, true));
        self::assertDirectoryDoesNotExist('delete-tree');

        file_put_contents('link-target', 'preserved');
        if (@symlink('link-target', 'delete-link')) {
            self::assertTrue((new DeleteTask(['path' => 'delete-link'], $this->logger()))->execute(false, true));
            self::assertFileDoesNotExist('delete-link');
            self::assertSame('preserved', file_get_contents('link-target'));
        }

        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => new DeleteTask(['path' => '../outside'], $this->logger()),
            'escapes project directory'
        );
    }

    /** Covers regular and debug echo output and required-parameter validation. */
    public function testEchoTaskWritesMessagesAndRejectsMissingMessage(): void
    {
        $logger = new Logger(true, false, true);
        $task = new EchoTask(['message' => 'echo-message'], $logger);

        ob_start();
        try {
            self::assertSame('echo', $task->getType());
            self::assertTrue($task->execute(false, false));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertIsString($output);
        self::assertStringContainsString('echo-message', $output);
        $this->assertThrows(\Exception::class, fn() => new EchoTask([], $this->logger()), 'Missing');
    }

    /** Covers successful and failing external processes, captured stderr, timeouts, and validation. */
    public function testExecTaskHandlesProcessResultsAndTimeouts(): void
    {
        $logger = $this->logger();
        $success = new ExecTask([
            'command' => '"' . PHP_BINARY . '"',
            'args' => ['-r', 'echo "process-ok";'],
        ], $logger);
        self::assertSame('exec', $success->getType());
        self::assertTrue($success->execute(false, true));

        $failure = new ExecTask([
            'command' => '"' . PHP_BINARY . '"',
            'args' => ['-r', 'exit(7);'],
        ], $logger);
        self::assertFalse($failure->execute(false, true));

        $stderr = new ExecTask([
            'command' => '"' . PHP_BINARY . '"',
            'args' => ['-r', 'fwrite(STDERR, "process-error"); exit(2);'],
        ], $logger);
        self::assertFalse($stderr->execute(false, true));

        $timeout = new ExecTask([
            'command' => '"' . PHP_BINARY . '"',
            'args' => ['-r', 'usleep(1500000);'],
            'timeout' => 0.1,
        ], $logger);
        self::assertFalse($timeout->execute(false, true));

        $this->assertThrows(InvalidArgumentException::class, fn() => new ExecTask(['command' => ' '], $logger));
        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => new ExecTask(['command' => PHP_BINARY, 'args' => ['ok', new \stdClass()]], $logger)
        );
        $this->assertThrows(InvalidArgumentException::class, fn() => new ExecTask(['command' => PHP_BINARY, 'timeout' => 0], $logger));
        $this->assertThrows(InvalidArgumentException::class, fn() => new ExecTask(['command' => PHP_BINARY, 'timeout' => 86401], $logger));
    }

    /** Covers directory and file archives, output exclusion, missing sources, and symlink rejection. */
    public function testZipTaskCreatesArchivesAndRejectsInvalidSources(): void
    {
        mkdir('archive-source/nested', 0777, true);
        file_put_contents('archive-source/root.txt', 'root-content');
        file_put_contents('archive-source/nested/child.txt', 'child-content');

        $task = new ZipTask(['source' => 'archive-source', 'output' => 'dist/archive.zip'], $this->logger());
        self::assertSame('archive', $task->getType());
        self::assertTrue($task->execute(false, true));

        $zip = new ZipArchive();
        self::assertTrue($zip->open('dist/archive.zip') === true);
        try {
            self::assertSame('root-content', $zip->getFromName('root.txt'));
            self::assertSame('child-content', $zip->getFromName('nested/child.txt'));
        } finally {
            $zip->close();
        }

        file_put_contents('single.txt', 'single');
        self::assertTrue((new ZipTask(['source' => 'single.txt', 'output' => 'dist/single.zip'], $this->logger()))->execute(false, true));
        self::assertTrue((new ZipTask(['source' => 'archive-source', 'output' => 'archive-source/self.zip'], $this->logger()))->execute(false, true));

        self::assertFalse((new ZipTask(['source' => 'missing', 'output' => 'dist/missing.zip'], $this->logger()))->execute(false, true));

        if (@symlink('single.txt', 'archive-source/nested/file-link')) {
            self::assertFalse((new ZipTask(['source' => 'archive-source', 'output' => 'dist/symlink.zip'], $this->logger()))->execute(false, true));
            self::assertFileDoesNotExist('dist/symlink.zip');

            self::assertTrue(symlink('single.txt', 'archive-source-link'));
            self::assertFalse((new ZipTask(['source' => 'archive-source-link', 'output' => 'dist/link-source.zip'], $this->logger()))->execute(false, true));
        }

        file_put_contents('zip-parent-blocker', 'file');
        self::assertFalse((new ZipTask(['source' => 'single.txt', 'output' => 'zip-parent-blocker/child.zip'], $this->logger()))->execute(false, true));

        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => new ZipTask(['source' => '../outside', 'output' => 'inside.zip'], $this->logger()),
            'escapes project directory'
        );
    }

    /** Covers the BaseTask shared path validation and filesystem boundary checks. */
    public function testBaseTaskRejectsMalformedEscapingAndDanglingPaths(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn() => new CreateDirectoryTask(['name' => '..'], $this->logger()));
        $this->assertThrows(InvalidArgumentException::class, fn() => new CreateDirectoryTask(['name' => "bad\0path"], $this->logger()));
        $this->assertThrows(InvalidArgumentException::class, fn() => new CreateDirectoryTask(['name' => '../outside'], $this->logger()));

        mkdir('link-target');
        if (@symlink('link-target', 'safe-link')) {
            self::assertTrue((new CreateDirectoryTask(['name' => 'safe-link/child'], $this->logger()))->execute(false, true));
            self::assertTrue(symlink('missing-target', 'dangling-link'));
            $this->assertThrows(
                InvalidArgumentException::class,
                fn() => new CreateDirectoryTask(['name' => 'dangling-link/child'], $this->logger()),
                'Dangling symlink'
            );
        }
    }

    /** Asserts the BaseTask fails when the process current directory can no longer be resolved. */
    public function testBaseTaskRejectsUnresolvableWorkingDirectory(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows does not allow removing the active working directory.');
        }

        $removed = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'removed-cwd';
        self::assertTrue(mkdir($removed));
        self::assertTrue(chdir($removed));
        self::assertTrue(rmdir($removed));

        try {
            $this->assertThrows(
                RuntimeException::class,
                fn() => new CreateDirectoryTask(['name' => 'child'], $this->logger()),
                'Unable to resolve current working directory'
            );
        } finally {
            chdir($this->temporaryDirectory);
        }
    }

    /** Exercises TaskExecutor's diagnostic branches for every non-silent failure category. */
    public function testTaskExecutorReportsNonSilentFailures(): void
    {
        $executor = new TaskExecutor();
        $factory = new TaskFactory($this->logger());

        $missing = new BuildConfiguration('Missing target', '', '', [
            'default' => ['tasks' => []],
        ], 'default');
        self::assertSame(1, $executor->executeTasks($missing, ['absent'], $factory, false, false));

        $withoutTasks = new BuildConfiguration('Missing tasks', '', '', [
            'default' => [],
        ], 'default');
        self::assertSame(1, $executor->executeTasks($withoutTasks, ['default'], $factory, false, false));

        $factoryFailure = new BuildConfiguration('Factory failure', '', '', [
            'default' => ['tasks' => [['type' => 'not-supported', 'params' => []]]],
        ], 'default');
        self::assertSame(1, $executor->executeTasks($factoryFailure, ['default'], $factory, false, false));

        $taskFailure = new BuildConfiguration('Task failure', '', '', [
            'default' => ['tasks' => [[
                'type' => 'exec',
                'params' => ['command' => PHP_BINARY, 'args' => ['-r', 'exit(12);']],
            ]]],
        ], 'default');

        ob_start();
        try {
            self::assertSame(1, $executor->executeTasks($taskFailure, ['default'], $factory, false, false));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertIsString($output);
        self::assertStringContainsString('Task failed', $output);
    }

    /** Covers CreateDirectoryTask validation, defensive state checks, and debug output. */
    public function testCreateDirectoryTaskCoversValidationAndDebugBranches(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn() => new CreateDirectoryTask([], $this->logger()));
        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => new CreateDirectoryTask(['name' => ''], $this->logger())
        );

        $defensive = new CreateDirectoryTask(['name' => 'valid-name'], $this->logger());
        $parameters = new \ReflectionProperty(\PhpMake\Task\BaseTask::class, 'params');
        $parameters->setValue($defensive, []);
        self::assertFalse($defensive->execute(false, true));

        $debugLogger = new Logger(true, false, true);
        mkdir('already-created');
        $existing = new CreateDirectoryTask(['name' => 'already-created'], $debugLogger);
        $created = new CreateDirectoryTask(['name' => 'created-with-debug'], $debugLogger);

        ob_start();
        try {
            self::assertTrue($existing->execute(false, false));
            self::assertTrue($created->execute(false, false));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertIsString($output);
        self::assertStringContainsString('already exists', $output);
        self::assertStringContainsString('Created directory', $output);
    }

    /** Exercises ExecTask defensive validation, debug logging, process-start failure, and forced termination. */
    public function testExecTaskCoversDefensiveAndDiagnosticBranches(): void
    {
        $logger = $this->logger();

        $invalidTimeout = new ExecTask(['command' => PHP_BINARY], $logger);
        (new \ReflectionProperty(\PhpMake\Task\BaseTask::class, 'params'))->setValue(
            $invalidTimeout,
            ['command' => PHP_BINARY, 'timeout' => 0]
        );
        self::assertFalse($invalidTimeout->execute(false, true));

        $emptyCommand = new ExecTask(['command' => PHP_BINARY], $logger);
        (new \ReflectionProperty(\PhpMake\Task\BaseTask::class, 'params'))->setValue(
            $emptyCommand,
            ['command' => ' ']
        );
        self::assertFalse($emptyCommand->execute(false, true));

        $debugTask = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', 'exit(0);'],
        ], new Logger(true, false, true));

        ob_start();
        try {
            self::assertTrue($debugTask->execute(false, true));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertIsString($output);
        self::assertStringContainsString('Executing process:', $output);

        if (PHP_OS_FAMILY !== 'Windows') {
            $previousHandler = set_error_handler(static fn(): bool => true);
            try {
                $cannotStart = new ExecTask(['command' => '/'], $logger);
                self::assertFalse($cannotStart->execute(false, true));
            } finally {
                restore_error_handler();
            }
        }

        if (PHP_OS_FAMILY !== 'Windows' && extension_loaded('pcntl')) {
            $ignoresTermination = new ExecTask([
                'command' => PHP_BINARY,
                'args' => [
                    '-r',
                    'pcntl_signal(SIGTERM, SIG_IGN); pcntl_async_signals(true); while (true) { usleep(10000); }',
                ],
                'timeout' => 0.05,
            ], $logger);
            self::assertFalse($ignoresTermination->execute(false, true));
        }
    }

    /** Returns a logger configured not to write files or emit regular output. */
    private function logger(): Logger
    {
        return new Logger(false, true, true);
    }

    /** Asserts that the requested build configuration returns a failed execution result. */
    private function assertExecutorFails(TaskExecutor $executor, TaskFactory $factory, array $targets, array $order): void
    {
        $configuration = new BuildConfiguration('Failed executor', '', '', $targets, 'default');
        self::assertSame(1, $executor->executeTasks($configuration, $order, $factory, false, true));
    }

    /** Asserts an operation throws the specified exception and optional message fragment. */
    private function assertThrows(string $exceptionClass, callable $operation, ?string $message = null): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            if ($message !== null) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
            return;
        }

        self::fail(sprintf('Expected exception %s was not thrown.', $exceptionClass));
    }

    /** Recursively removes temporary files without following symbolic links. */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                unlink($directory);
            }
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
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

/** Simulates a build file that is reported as readable but cannot be opened. */
final class FailingReadStreamWrapper
{
    public mixed $context;

    /** Refuses to open a stream to trigger BuildLoader's explicit read-failure branch. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    /** Makes the stream appear to be a readable regular file. */
    public function url_stat(string $path, int $flags): array
    {
        return [
            'mode' => 0100444,
            'size' => 1,
            'mtime' => time(),
            'atime' => time(),
            'ctime' => time(),
        ];
    }
}
