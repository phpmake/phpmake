<?php

declare(strict_types=1);

namespace PhpMake\Tests\Integration;

use PhpMake\Tests\Support\TemporaryDirectoryTestHelper;
use PHPUnit\Framework\TestCase;

/** Verifies that --init never follows an existing dangling destination symlink. */
final class InitSymlinkTest extends TestCase
{
    use ProcessTestHelper;\n    use TemporaryDirectoryTestHelper;

    /** A dangling build.json symlink is rejected without creating its target. */
    public function testInitRejectsDanglingBuildConfigSymlink(): void
    {
        $root = dirname(__DIR__, 2);
        $workspace = $this->createTemporaryDirectory('phpmake-init-');
        $project = $workspace . DIRECTORY_SEPARATOR . 'project';
        $target = $workspace . DIRECTORY_SEPARATOR . 'target';
        try {
            // Arrange
            self::assertTrue(mkdir($project));
            $link = $project . DIRECTORY_SEPARATOR . 'build.json';
            if (!@symlink($target, $link)) {
                self::markTestSkipped('Symbolic-link creation is unavailable in this environment.');
            }

            // Act
            $result = $this->runTestProcess(
                [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpmake.php', '--init'],
                $project
            );

            // Assert
            self::assertFalse($result['timedOut'], $result['stdout'] . $result['stderr']);
            self::assertSame(1, $result['exitCode'], $result['stdout'] . $result['stderr']);
            self::assertTrue(is_link($link));
            self::assertFileDoesNotExist($target);
        } finally {
            if (isset($link) && is_link($link)) {
                unlink($link);
            }

            if (is_file($target)) {
                unlink($target);
            }

            $this->removeTemporaryDirectory($workspace);
        }
    }
}
