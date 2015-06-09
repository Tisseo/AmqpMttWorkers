<?php

require_once __DIR__ . '/vendor/autoload.php';

include(__DIR__ . '/config.inc.php');

use CanalTP\AMQPMttWorkers\Worker;


if (!isset($argv[1])) {
    echo "php pdfGenerationWorker.php name [Number Tries]\n";
    exit(0);
}

$worker = new Worker($argv[1], in_array('--std-out', $argv));

if (isset($argv[2]) && is_int($argv[2])) {
    $worker->setNbTries($argv[2]);
}

$worker->init();
$worker->run();

exit(0);
