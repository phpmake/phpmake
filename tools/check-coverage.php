<?php

declare(strict_types=1);

/**
 * Enforces minimum class and method coverage from PHPUnit's Clover report.
 *
 * Usage: php tools/check-coverage.php [coverage.xml]
 */

$reportPath = $argv[1] ?? 'coverage.xml';
if (!is_file($reportPath) || !is_readable($reportPath)) {
    fwrite(STDERR, sprintf("Coverage report '%s' is missing or unreadable.%s", $reportPath, PHP_EOL));
    exit(1);
}

$report = file_get_contents($reportPath);
if (
    $report === false
    || !str_contains($report, '<coverage')
    || !str_contains($report, '</coverage>')
) {
    fwrite(STDERR, sprintf("Coverage report '%s' is not a valid Clover XML document.%s", $reportPath, PHP_EOL));
    exit(1);
}

$classMatches = [];
$matchedClasses = preg_match_all(
    '/<class\b[^>]*>\s*<metrics\b([^>]*)\/>\s*<\/class>/s',
    $report,
    $classMatches
);

if ($matchedClasses === false || $matchedClasses === 0) {
    fwrite(STDERR, "Coverage report contains no class metrics." . PHP_EOL);
    exit(1);
}

/** Reads an integer attribute from a Clover metrics tag. */
$readMetric = static function (string $attributes, string $name): int {
    $matched = preg_match(
        '/(?:^|\s)' . preg_quote($name, '/') . '="(\d+)"/',
        $attributes,
        $match
    );

    return $matched === 1 ? (int) $match[1] : 0;
};

$totalClasses = 0;
$coveredClasses = 0;
$totalMethods = 0;
$coveredMethods = 0;

foreach ($classMatches[1] as $attributes) {
    $methodCount = $readMetric($attributes, 'methods');
    $coveredMethodCount = $readMetric($attributes, 'coveredmethods');
    $elementCount = $readMetric($attributes, 'elements');
    $coveredElementCount = $readMetric($attributes, 'coveredelements');

    // Ignore classes with no executable methods, such as constant-only Config.
    if ($methodCount === 0) {
        continue;
    }

    ++$totalClasses;
    $totalMethods += $methodCount;
    $coveredMethods += $coveredMethodCount;

    if (
        $coveredMethodCount === $methodCount
        && $elementCount > 0
        && $coveredElementCount === $elementCount
    ) {
        ++$coveredClasses;
    }
}

if ($totalClasses === 0 || $totalMethods === 0) {
    fwrite(STDERR, "Coverage report contains no executable production classes or methods." . PHP_EOL);
    exit(1);
}

$classPercentage = 100 * $coveredClasses / $totalClasses;
$methodPercentage = 100 * $coveredMethods / $totalMethods;

printf(
    "Class coverage: %.2f%% (%d/%d)%s",
    $classPercentage,
    $coveredClasses,
    $totalClasses,
    PHP_EOL
);
printf(
    "Method coverage: %.2f%% (%d/%d)%s",
    $methodPercentage,
    $coveredMethods,
    $totalMethods,
    PHP_EOL
);

$minimum = 70.0;
$failures = [];
if ($classPercentage < $minimum) {
    $failures[] = sprintf('class coverage %.2f%% is below %.0f%%', $classPercentage, $minimum);
}
if ($methodPercentage < $minimum) {
    $failures[] = sprintf('method coverage %.2f%% is below %.0f%%', $methodPercentage, $minimum);
}

if ($failures !== []) {
    fwrite(STDERR, "Coverage threshold failed: " . implode('; ', $failures) . "." . PHP_EOL);
    exit(1);
}

echo "Coverage thresholds passed." . PHP_EOL;
