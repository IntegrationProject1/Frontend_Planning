<?php
/**
 * Plugin Name: User Delete Consumer
 * Description: Verwijdert users op basis van RabbitMQ.
 * Version: 1.0
 * Author: Mathias Mertens
 */

require_once '/var/www/html/wp-load.php';
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Verwerkt een DELETE-bericht en verwijdert de user.
 */
function process_user_deletion($xml_string) {
    $xml = simplexml_load_string($xml_string);
    if (!$xml || (string)$xml->ActionType !== 'DELETE') {
        return;
    }

    // Parse de UUID als xs:dateTime met microseconden
    try {
        $dtUuid = \DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', (string)$xml->UUID);
        if (! $dtUuid) {
            throw new \Exception('Format mismatch');
        }
        $uuidString = $dtUuid->format('Y-m-d\TH:i:s.v\Z');
    } catch (\Exception $e) {
        error_log("Ongeldig UUID-datumformaat in delete: " . $e->getMessage());
        return;
    }

    // Zoek de gebruiker via meta 'UUID'.
    $users = get_users([
        'meta_key'   => 'UUID',
        'meta_value' => $uuidString,
        'number'     => 1,
        'fields'     => 'ID',
    ]);
    if (empty($users)) {
        error_log("Geen gebruiker gevonden met UUID: {$uuidString}");
        return;
    }

    $user_id = $users[0];

    // Verwijder de user
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user_id);
    error_log("Gebruiker succesvol verwijderd met user_id: {$user_id}");
}

add_action('init', function () {
    // Haal RabbitMQ instellingen
    $host = getenv('RABBITMQ_HOST');
    $port = getenv('RABBITMQ_PORT');
    $user = getenv('RABBITMQ_USER');
    $pass = getenv('RABBITMQ_PASSWORD');

    $exchange    = 'user';
    $queue_name  = 'frontend_user_delete';
    $routing_key = 'frontend.user.delete';

    try {
        // Verbind met RabbitMQ
        $connection = new AMQPStreamConnection($host, $port, $user, $pass);
        $channel    = $connection->channel();

        // Declareer exchange en queue
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->queue_declare($queue_name, false, true, false, false);
        $channel->queue_bind($queue_name, $exchange, $routing_key);

        // Callback voor delete
        $callback = function (AMQPMessage $msg) use ($channel) {
            error_log("Delete-bericht ontvangen: " . $msg->body);
            process_user_deletion($msg->body);
            $channel->basic_ack($msg->delivery_info['delivery_tag']);
        };

        $channel->basic_consume($queue_name, '', false, false, false, false, $callback);

        error_log("Wachten op delete-berichten in queue '{$queue_name}'...");
        while (count($channel->callbacks)) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log("RabbitMQ Delete Consumer Fout: " . $e->getMessage());
    }
});