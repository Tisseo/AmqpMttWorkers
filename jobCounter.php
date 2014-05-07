<?php
// This producer was just made for testing/dev purposes
// Message publishing should be done by Mtt application
require_once __DIR__ . '/vendor/autoload.php';
include(__DIR__ . '/config.inc.php');

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPConnection(HOST, PORT, USER, PASS, VHOST);
$channel = $connection->channel();

$channel->exchange_declare('pdf_gen_exchange', 'topic', false, true, false);

$ackQueueId = 'ack_queue.divia.pdf_gen.1';
while (true) {
    list($queue_name, $jobs, $consumers) = $channel->queue_declare($ackQueueId, false, true, false, false);
    echo "Queue $queue_name has $jobs job(s) and $consumers idle consumer(s)\r\n";
    sleep(10);
}