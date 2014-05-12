<?php
// This file is under development :p

require_once __DIR__ . '/vendor/autoload.php';
include(__DIR__ . '/config.inc.php');

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

use CanalTP\MediaManagerBundle\DataCollector\MediaDataCollector;
// MTTBundle
use CanalTP\MttBundle\Services\PdfHashingLib;
use CanalTP\MttBundle\Services\PdfGenerator;
use CanalTP\MttBundle\Services\MediaManager;
use CanalTP\MttBundle\Services\CurlProxy;
use CanalTP\MttBundle\Services\AmqpPdfGenPublisher;

//AmqpMttWorkers
use CanalTP\AMQPMttWorkers\TimetableMediaBuilder;

$curlProxy = new CurlProxy();
$pdfHashingLib = new PdfHashingLib($curlProxy);

$connection = new AMQPConnection(HOST, PORT, USER, PASS, VHOST);
$channel = $connection->channel();

$channel->exchange_declare(AmqpPdfGenPublisher::EXCHANGE_NAME, 'topic', false, true, false);

list($queue_name, ,) = $channel->queue_declare(AmqpPdfGenPublisher::WORK_QUEUE_NAME, false, true, false, false);
$channel->queue_bind($queue_name, AmqpPdfGenPublisher::EXCHANGE_NAME, "*.pdf_gen");

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
            $payload->timetableParams->externalNetworkId,
            $payload->timetableParams->externalRouteId,
            $payload->timetableParams->externalStopPointId,
            $payload->timetableParams->seasonId
        );
        echo "Generation result :" . $filepath ? $filepath : 'NOK';
        echo "\n--------\n";
    }
    
    // acknowledgement part
    // push ack data into expected queue
    if (isset($filepath)) {
        $payload->generated = true;
        $payload->generationResult = new \stdClass;
        $payload->generationResult->filepath = $filepath;
        $payload->generationResult->pdfHash = $hash;
        $payload->generationResult->created = time();
    } else {
        $payload->generated = false;
    }
    $ackMsg = new AMQPMessage(
        json_encode($payload),
        array(
            'delivery_mode' => 2,
            'content_type'  => 'application/json'
        )
    );
    // publish to ack queue
    $msg->delivery_info['channel']->basic_publish($ackMsg, AmqpPdfGenPublisher::EXCHANGE_NAME, $msg->get('reply_to'), true);
    echo " [x] Sent ",$msg->get('reply_to'),':',print_r($payload, true)," \n";
    echo "\n--------\n";
    // acknowledge broker
    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);

    // sleep(10);
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