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

$document = new DOMDocument();
$previousErrors = libxml_use_internal_errors(true);
$loaded = $document->load($reportPath);
libxml_clear_errors();
libxml_use_internal_errors($previousErrors);

if (!$loaded) {
    fwrite(STDERR, sprintf("Coverage report '%s' is not valid XML.%s", $reportPath, PHP_EOL));
    exit(1);
}

$xpath = new DOMXPath($document);
$classes = $xpath->query('/coverage/project/package/file/class');
if ($classes === false) {
    fwrite(STDERR, "Unable to read class metrics from the coverage report." . PHP_EOL);
    exit(1);
}

$totalClasses = 0;
$coveredClasses = 0;
$totalMethods = 0;
$coveredMethods = 0;

foreach ($classes as $class) {
    $metrics = $xpath->query('./metrics', $class)?->item(0);
    if (!$metrics instanceof DOMElement) {
        continue;
    }

    $methodCount = (int) $metrics->getAttribute('methods');
    $coveredMethodCount = (int) $metrics->getAttribute('coveredmethods');
    $elementCount = (int) $metrics->getAttribute('elements');
    $coveredElementCount = (int) $metrics->getAttribute('coveredelements');

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
