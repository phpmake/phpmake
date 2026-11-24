# Code Coverage

CI collects PHPUnit line and branch coverage on PHP 8.3 with Xdebug.

## Required gates

- Overall executable-line coverage: **at least 80%**.
- The following files: **100% executable-line coverage**.
  - `src/Build/BuildExecutor.php`
  - `src/Build/BuildValidator.php`
  - `src/Cli/CliParser.php`
  - `src/Process/CommandLineParser.php`
  - `src/Process/WindowsProcessLauncher.php`
  - `src/Target/DependencyResolver.php`
  - `src/Task/TaskDefinitionRegistry.php`
- Branch coverage is collected and reported; there is no project-wide branch percentage gate.

The CI workflow validates Clover and enforces these thresholds. It publishes JUnit, Clover, and HTML reports as the `phpunit-reports` artifact. Do not quote a current percentage without inspecting a successful run's report.

## Local report

Use PHP 8.1+ with Xdebug and PHPUnit 10.5 for path coverage:

```sh
composer require --dev --with-all-dependencies phpunit/phpunit:^10.5
sed 's#9.6/phpunit.xsd#10.5/phpunit.xsd#' phpunit.xml > phpunit-coverage.xml
mkdir -p build
XDEBUG_MODE=coverage vendor/bin/phpunit --configuration phpunit-coverage.xml --path-coverage --coverage-clover=build/coverage.xml --coverage-html=build/coverage-html --coverage-text
```

The GitHub Actions coverage job is the authoritative gate. PHP 8.0 compatibility uses the project's normal PHPUnit resolution and does not run path coverage.

## Test policy

Use PHPUnit, Arrange / Act / Assert, and real behavior without mocks. Test observable results, failures, boundaries, and platform-specific behavior. A skipped test is not evidence that the skipped platform behavior passed.
