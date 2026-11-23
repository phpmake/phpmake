<?php

// Enforce strict type checking only for THIS file.
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

\PhpMake\PlatformSupport::assertSupported();

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', 'On');
ini_set('ignore_repeated_errors', 'On');
ini_set('memory_limit', '-1');
putenv('APPLICATION_ENVIRONMENT=test');
