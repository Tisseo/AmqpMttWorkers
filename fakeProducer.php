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
// bind queue in consumer so messages will be kept even if there is no worker yet
list($queue_name, ,) = $channel->queue_declare("pdf_gen_queue", false, true, false, false);
$channel->queue_bind($queue_name, 'pdf_gen_exchange', "*.pdf_gen");

// let's publish
publishMessages($channel);

function publishMessages($channel, $task_id = 1, $routingKey = 'divia.pdf_gen', $limit = 100) {
    $i = 0;
    $ackQueueName = 'ack_queue.' . $routingKey . '.task_' . $task_id;
    while ($i < $limit) {
        $payload = array(
            'pdfGeneratorUrl'   => 'http://223.0.0.128/pdfGenerator/web/',
            'url' => "http://223.0.0.128/SamApp/web/mtt/timetable/view/networks/network:Filbleu/line/line:TTR:Nav62/route/route:TTR:Nav155/seasons/1/stopPoints/stop_point:TTR:SP:JUSTB-1?" . $i,
            'pdfHash'           => 'unsupermd5deoufpaslisible',
            'cssVersion'        => '1',
            'timetableParams' => array(
                'externalNetworkId' => 'network:Filbleu',
                'externalRouteId' => 'route:TTR:Nav155',
                'externalStopPointId' => 'stop_point:TTR:SP:JUSTB-1',
                'seasonId' => '1',
            ),
            'layoutParams'  => array(
                'orientation' => 'landscape'
            )
        );
        $msg = new AMQPMessage(
            json_encode($payload),
            array(
                'delivery_mode' => 2,
                'content_type'  => 'application/json',
                'reply_to'      => $ackQueueName
            )
        );
        $channel->basic_publish($msg, 'pdf_gen_exchange', $routingKey, true);
        echo " [x] Sent ",$routingKey,':',print_r($payload, true)," \n";
        $i++;
    }
    // declare ack queue
    $channel->queue_declare($ackQueueName, false, true, false, false);
    $channel->queue_bind($ackQueueName, 'pdf_gen_exchange', $ackQueueName);
}

function shutdown($channel, $connection)
{
    $channel->close();
    $connection->close();
}
register_shutdown_function('shutdown', $channel, $connection);

?>