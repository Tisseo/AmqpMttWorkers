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
use CanalTP\MttBundle\Services\Amqp\Channel;

//AmqpMttWorkers
use CanalTP\AMQPMttWorkers\TimetableMediaBuilder;

$curlProxy = new CurlProxy();
$pdfHashingLib = new PdfHashingLib($curlProxy);

$channelLib = new Channel(HOST, USER, PASS, PORT, VHOST);
$channel = $channelLib->getChannel();

list($queue_name, ,) = $channel->queue_declare($channelLib->getPdfGenQueueName(), false, true, false, false);
$channel->queue_bind($queue_name, $channelLib->getExchangeName(), "*.pdf_gen");

$ttMediaBuilder = new TimetableMediaBuilder();
// how many tries attempted for each message
$nbTries = isset($argv[1]) && is_int($argv[1]) ?  $argv[1] : 3;

$ackMessage = function ($msgToAck, $ackPayload) use ($channelLib){
    $ackMsg = new AMQPMessage(
        json_encode($ackPayload),
        array(
            'delivery_mode' => 2,
            'content_type'  => 'application/json'
        )
    );
    // publish to ack queue
    $msgToAck->delivery_info['channel']->basic_publish(
        $ackMsg, 
        $channelLib->getExchangeName(), 
        $msgToAck->get('reply_to'), 
        true
    );
    echo " [x] Sent ",$msgToAck->get('reply_to'),':',print_r($ackPayload, true)," \n";
    echo "\n--------\n";
    // acknowledge broker
    $msgToAck->delivery_info['channel']->basic_ack($msgToAck->delivery_info['delivery_tag']);
};

$process_message = function($msg) use ($curlProxy, $pdfHashingLib, $ttMediaBuilder, $channelLib, $ackMessage, $nbTries)
{
    try{
        $payload = json_decode($msg->body);
        echo "\n--------\n";
        print_r($payload);
        echo "\n--------\n";
        $html = $curlProxy->get($payload->url);
        if (empty($html)) {
            throw new \Exception("Got empty response $html from server, url: " . ($payload->url));
        } else {
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
            $ackMessage($msg, $payload);
            
        }
    } catch(\Exception $e){
        echo "\n----  Error catched  ----\n";
        echo $e->getMessage() . "\n";
        echo "\n--------\n";
        $payload = json_decode($msg->body);
        // is it the first try? if so initialize nbTries variable
        if (!isset($payload->nbTries)) {
            $payload->nbTries = 0;
        }
        $payload->nbTries++;
        echo "\n----  Nb tries: ", $payload->nbTries ,"  ----\n";
        if ($payload->nbTries == $nbTries) {
            echo "\n----  Message Redelivered in error  ----\n";
            $payload->generated = false;
            $payload->error = $e->getMessage();
            $ackMessage($msg, $payload);
        } else {
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            // republish it with new payload
            echo "\n----  Republish to Retry  ----\n";
            $newMsg = new AMQPMessage(
                json_encode($payload),
                array(
                    'delivery_mode' => 2,
                    'content_type'  => 'application/json',
                    'reply_to'      => $msg->get('reply_to')
                )
            );
            $channelLib->getChannel()->basic_publish($newMsg, $channelLib->getExchangeName(), $msg->delivery_info['routing_key'], true);
        }
    }
};
$channel->basic_qos(null, 1, null);
$channel->basic_consume($queue_name, 'pdfWorker', false, false, false, false, $process_message);

function shutdown($channelLib)
{
    $channelLib->close();
}
register_shutdown_function('shutdown', $channelLib);

// Loop as long as the channel has callbacks registered
while (count($channel->callbacks)) {
    $channel->wait();
}