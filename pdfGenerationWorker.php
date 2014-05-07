<?php
// This file is under development :p

require_once __DIR__ . '/vendor/autoload.php';
include(__DIR__ . '/config.inc.php');

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

// MTTBundle
use CanalTP\MttBundle\Services\PdfHashingLib;
use CanalTP\MttBundle\Services\PdfGenerator;
use CanalTP\MttBundle\Services\MediaManager;
use CanalTP\MediaManagerBundle\DataCollector\MediaDataCollector;
use CanalTP\MttBundle\Services\CurlProxy;

//AmqpMttWorkers
use CanalTP\AMQPMttWorkers\TimetableMediaBuilder;

$curlProxy = new CurlProxy();
$pdfHashingLib = new PdfHashingLib($curlProxy);

$connection = new AMQPConnection(HOST, PORT, USER, PASS, VHOST);
$channel = $connection->channel();

$channel->exchange_declare('pdf_gen_exchange', 'topic', false, true, false);

list($queue_name, ,) = $channel->queue_declare("pdf_gen_queue", false, true, false, false);
$channel->queue_bind($queue_name, 'pdf_gen_exchange', "*.pdf_gen");

$ttMediaBuilder = new TimetableMediaBuilder();

$process_message = function($msg) use ($curlProxy, $pdfHashingLib, $ttMediaBuilder)
{
    $payload = json_decode($msg->body);
    echo "\n--------\n";
    print_r($payload);
    echo "\n--------\n";
    $html = $curlProxy->get($payload->url);
    $hash = $pdfHashingLib->getPdfHash($html, $payload->cssVersion);
    echo "pdf hash: " . ($hash);
    echo "\n--------\n";
    
    if ($hash != $payload->pdfHash) {
        $pdfGenerator = new PdfGenerator($curlProxy, $payload->pdfGeneratorUrl, false);
        $pdfPath = $pdfGenerator->getPdf($payload->url, $payload->layoutParams->orientation);
        echo "pdfPath: " . $pdfPath;
        $filepath = $ttMediaBuilder->saveFile(
            $pdfPath,
            $payload->mediaManagerParams->externalNetworkId,
            $payload->mediaManagerParams->externalRouteId,
            $payload->mediaManagerParams->externalStopPointId,
            $payload->mediaManagerParams->seasonId
        );
        echo "Generation result :" . $filepath ? $filepath : 'NOK';
        echo "\n--------\n";
    }
    
    //acknowledgement
    // if ($msg->body == 'good') {
        $msg->delivery_info['channel']->
            basic_ack($msg->delivery_info['delivery_tag']);
    // } else {
        // $msg->delivery_info['channel']->
            // basic_nack($msg->delivery_info['delivery_tag']);
    // }
    sleep(10);
};
$channel->basic_qos(null, 1, null);
$channel->basic_consume($queue_name, 'pdfWorker', false, false, false, false, $process_message);

function shutdown($channel, $connection)
{
    $channel->close();
    $connection->close();
}
register_shutdown_function('shutdown', $channel, $connection);

// Loop as long as the channel has callbacks registered
while (count($channel->callbacks)) {
    $channel->wait();
}