<?php

declare(strict_types=1);

/**
 * Validates the generated Clover report against project coverage requirements.
 *
 * Usage: php tools/check-coverage.php [path/to/coverage.xml]
 */

$reportPath = $argv[1] ?? 'build/coverage.xml';
$minimumLineCoverage = 80.0;

if (!is_file($reportPath) || !is_readable($reportPath)) {
    fwrite(STDERR, sprintf("Coverage report is missing or unreadable: %s\n", $reportPath));
    exit(1);
}

$report = simplexml_load_file($reportPath);
if ($report === false || !isset($report->project->metrics)) {
    fwrite(STDERR, "Coverage report is not a valid Clover XML document.\n");
    exit(1);
}

$projectMetrics = $report->project->metrics;
$statements = (int) ($projectMetrics['statements'] ?? 0);
$coveredStatements = (int) ($projectMetrics['coveredstatements'] ?? 0);
$conditionals = (int) ($projectMetrics['conditionals'] ?? 0);
$coveredConditionals = (int) ($projectMetrics['coveredconditionals'] ?? 0);

if ($statements < 1 || $coveredStatements < 0 || $coveredStatements > $statements) {
    fwrite(STDERR, "Clover report contains invalid statement coverage metrics.\n");
    exit(1);
}

if ($conditionals < 1 || $coveredConditionals < 0 || $coveredConditionals > $conditionals) {
    fwrite(STDERR, "Branch coverage data is missing or invalid. Ensure Xdebug and PHPUnit branch coverage are enabled.\n");
    exit(1);
}

$lineCoverage = ($coveredStatements / $statements) * 100;
$branchCoverage = ($coveredConditionals / $conditionals) * 100;

printf(
    "Line coverage: %.2f%% (%d/%d); required: %.2f%%\n",
    $lineCoverage,
    $coveredStatements,
    $statements,
    $minimumLineCoverage
);
printf(
    "Branch coverage: %.2f%% (%d/%d)\n",
    $branchCoverage,
    $coveredConditionals,
    $conditionals
);

if ($lineCoverage + 0.000001 < $minimumLineCoverage) {
    fwrite(STDERR, sprintf(
        "Coverage gate failed: line coverage %.2f%% is below %.2f%%.\n",
        $lineCoverage,
        $minimumLineCoverage
    ));
    exit(1);
}

/*
 * These security- and correctness-critical contracts are required to retain
 * complete executable-line coverage. New code in these files must be tested.
 */
$criticalFiles = [
    'src/Build/BuildExecutor.php' => 'Build target execution and dispatch',
    'src/Build/BuildValidator.php' => 'Build configuration validation',
    'src/Cli/CliParser.php' => 'CLI input and option parsing',
    'src/Process/CommandLineParser.php' => 'Shell-free command argument parsing',
    'src/Process/WindowsProcessLauncher.php' => 'Windows process argument transport',
    'src/Target/DependencyResolver.php' => 'Dependency graph resolution and cycle detection',
    'src/Task/TaskDefinitionRegistry.php' => 'Built-in task type and parameter contracts',
];

$reportedFiles = [];
foreach ($report->project->package as $package) {
    foreach ($package->file as $file) {
        $path = str_replace('\\\\', '/', (string) $file['name']);
        foreach ($criticalFiles as $criticalPath => $description) {
            if (substr($path, -strlen($criticalPath)) !== $criticalPath) {
                continue;
            }

            $metrics = $file->metrics;
            $fileStatements = (int) ($metrics['statements'] ?? 0);
            $fileCoveredStatements = (int) ($metrics['coveredstatements'] ?? 0);
            $reportedFiles[$criticalPath] = [
                'description' => $description,
                'statements' => $fileStatements,
                'covered' => $fileCoveredStatements,
            ];
        }
    }
}

$criticalFailed = false;
foreach ($criticalFiles as $criticalPath => $description) {
    if (!isset($reportedFiles[$criticalPath])) {
        fwrite(STDERR, sprintf("Critical coverage entry is missing: %s\n", $criticalPath));
        $criticalFailed = true;
        continue;
    }

    $metrics = $reportedFiles[$criticalPath];
    $total = $metrics['statements'];
    $covered = $metrics['covered'];
    $percentage = $total > 0 ? ($covered / $total) * 100 : 0.0;
    printf("Critical: %s — %.2f%% (%d/%d) — %s\n", $criticalPath, $percentage, $covered, $total, $description);

    if ($total < 1 || $covered !== $total) {
        $criticalFailed = true;
    }
}

if ($criticalFailed) {
    fwrite(STDERR, "Coverage gate failed: every critical file listed above must have 100% executable-line coverage.\n");
    exit(1);
}

echo "Coverage requirements satisfied.\n";
