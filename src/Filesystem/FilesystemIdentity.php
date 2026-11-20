<?php

declare(strict_types=1);

namespace PhpMake\Filesystem;

/** Compares filesystem paths by identity rather than textual spelling. */
final class FilesystemIdentity
{
    /** Returns whether two paths resolve to the same filesystem entry. */
    public static function sameFile(string $left, string $right, bool $caseInsensitiveFallback = false): bool
    {
        clearstatcache(true, $left);
        clearstatcache(true, $right);

        $leftStat = @stat($left);
        $rightStat = @stat($right);
        if (is_array($leftStat) && is_array($rightStat)) {
            return $leftStat['dev'] === $rightStat['dev']
                && $leftStat['ino'] === $rightStat['ino'];
        }

        return $caseInsensitiveFallback && PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }
}
