<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Process\CommandLineParser;
use PHPUnit\Framework\TestCase;

/** Tests shell-free command-line tokenization. */
final class CommandLineParserTest extends TestCase
{
    /** Accepts balanced single and double quoted arguments. */
    public function testHasBalancedQuotesForValidCommand(): void
    {
        // Arrange
        $command = 'php "script with spaces.php" \'single quoted\'';

        // Act
        $balanced = CommandLineParser::hasBalancedQuotes($command);

        // Assert
        self::assertTrue($balanced);
    }

    /** Rejects an unterminated quoted argument. */
    public function testHasBalancedQuotesRejectsUnterminatedQuote(): void
    {
        // Arrange
        $command = 'php "unterminated';

        // Act
        $balanced = CommandLineParser::hasBalancedQuotes($command);

        // Assert
        self::assertFalse($balanced);
    }

    /** Accepts escaped quotes and backslashes inside a quoted argument. */
    public function testHasBalancedQuotesWithEscapedCharacters(): void
    {
        // Arrange
        $command = 'php "escaped \\"quote\\" and \\\\ path"';

        // Act
        $balanced = CommandLineParser::hasBalancedQuotes($command);

        // Assert
        self::assertTrue($balanced);
    }

    /** Preserves spaces inside quoted tokens and Windows path separators. */
    public function testTokenizePreservesQuotedArgumentsAndWindowsPaths(): void
    {
        // Arrange
        $command = 'php "C:\\Program Files\\tool.php" \'two words\'';

        // Act
        $arguments = CommandLineParser::tokenize($command);

        // Assert
        self::assertSame(['php', 'C:\\Program Files\\tool.php', 'two words'], $arguments);
    }
}
