<?php

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

// formatter:
$formatter = new LineFormatter(null, null, false, true);

// create a log channel
$debug        = new Logger('debug');
$debugHandler = new RotatingFileHandler('logs/debug.log', 5, Logger::DEBUG);
$debugHandler->setFormatter($formatter);
$debug->pushHandler($debugHandler);

// create a log channel for normal logs:
$logger  = new Logger('logger');
$handler = new RotatingFileHandler('logs/logs.log', 5, Logger::DEBUG);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

// the last "true" here tells it to remove empty []'s


// add records to the log
//$log->warning('Foo');
//$log->error('Bar');