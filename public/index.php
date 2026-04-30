<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
define('TESTBENCH_WORKING_PATH', dirname(__DIR__));

require __DIR__ . '/../vendor/orchestra/testbench-core/laravel/bootstrap/autoload.php';

/** @var Application $app */
$app = require_once __DIR__ . '/../vendor/orchestra/testbench-core/laravel/bootstrap/app.php';

$app->handleRequest(Request::capture());
