<?php

declare(strict_types=1);

/**
 * HTTP entry point (front controller) for the CarMoneyLab pre-scoring service.
 * Bootstraps the Slim application via AppFactory and dispatches the current request.
 * All routing, middleware and request handling live behind this single entrypoint.
 */

use CarMoneyLab\AppFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

AppFactory::create()->run();
