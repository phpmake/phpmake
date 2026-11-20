<?php

declare(strict_types=1);

namespace PhpMake\Process;

/** Parses a command string into argv tokens without invoking a shell. */
final class CommandLineParser
{
    /** Rejects commands containing an unterminated single or double quote. */
    public static function hasBalancedQuotes(string $command): bool
    {
        $quote = null;
        $length = strlen($command);
        for ($i = 0; $i < $length; ++$i) {
            $character = $command[$i];
            if ($quote !== null) {
                if ($character === '\\' && $i + 1 < $length
                    && ($command[$i + 1] === $quote || $command[$i + 1] === '\\')) {
                    ++$i;
                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            }
        }

        return $quote === null;
    }

    /**
     * Tokenizes a command while preserving Windows path separators.
     *
     * @return list<string>
     */
    public static function tokenize(string $command): array
    {
        preg_match_all('/"((?:\\\\.|[^"])*)"|\'((?:\\\\.|[^\'])*)\'|(\S+)/', $command, $matches, PREG_SET_ORDER);
        $argv = [];

        foreach ($matches as $match) {
            if ($match[1] !== '') {
                $token = preg_replace('/\\\\(["\\\\])/', '$1', $match[1]);
            } elseif ($match[2] !== '') {
                $token = preg_replace('/\\\\([\'\\\\])/', '$1', $match[2]);
            } else {
                $token = $match[3];
            }

            $argv[] = $token;
        }

        return $argv;
    }
}
