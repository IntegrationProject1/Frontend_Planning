<?php
/**
 * Plugin Name: RabbitMQ User Delete
 * Description: Stuur gebruikers-ID naar RabbitMQ wanneer een gebruiker wordt verwijderd.
 * Version: 1.1
 * Author: By Youmni Malha
 */

require_once '/var/www/html/wp-load.php';

// Laad de PHP AMQP bibliotheek
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Functie om de gebruikers-ID naar RabbitMQ te sturen wanneer een gebruiker wordt verwijderd
function send_user_delete_to_rabbitmq($user_id) {
    try {

        $uuid = get_user_meta($user_id, 'UUID', true);

        if (empty($uuid)) {
            error_log(" [!] Geen UUID gevonden voor user_id: $user_id");
            return;
        }

        // Maak een XML van de gebruikers-ID
        $xml = new SimpleXMLElement('<UserMessage/>');
        $xml->addChild('ActionType', 'DELETE');
        $xml->addChild('UUID', $uuid);
        $xml->addChild('TimeOfAction', gmdate('Y-m-d\TH:i:s\Z'));

        $xml_string = $xml->asXML();

        // RabbitMQ instellingen
        $host = getenv('RABBITMQ_HOST');
        $port = getenv('RABBITMQ_PORT');
        $user = getenv('RABBITMQ_USER');
        $password = getenv('RABBITMQ_PASSWORD');
        $exchange = 'user';

        // Maak verbinding met RabbitMQ
        $connection = new AMQPStreamConnection($host, $port, $user, $password);
        $channel = $connection->channel();
        $channel->exchange_declare($exchange, 'topic', false, true, false);

        // Definieer de queues en routing keys
        $queues = [
            [
                'name' => 'facturatie_user_delete',
                'routing_key' => 'facturatie.user.delete'
            ],
            [
                'name' => 'crm_user_delete',
                'routing_key' => 'crm.user.delete'
            ],
            [
                'name' => 'kassa_user_delete',
                'routing_key' => 'kassa.user.delete'
            ]
        ];

        // Declareer en bind elke queue, verstuur het bericht
        $msg = new AMQPMessage($xml_string, ['content_type' => 'text/xml']);
        foreach ($queues as $queue) {
            $channel->queue_declare($queue['name'], false, true, false, false);
            $channel->queue_bind($queue['name'], $exchange, $queue['routing_key']);
            $channel->basic_publish($msg, $exchange, $queue['routing_key']);
            error_log(" [x] User deletion data sent to {$queue['name']} with routing key {$queue['routing_key']}");
        }

        error_log(' [x] User deletion data sent to all queues for UUID: ' . $user_id);

        // Sluit de verbinding
        $channel->close();
        $connection->close();

    } catch (Exception $e) {
        error_log('Error sending user deletion to RabbitMQ: ' . $e->getMessage());
    }
}

// Voeg de actie toe voor de verwijdering van een gebruiker
function handle_user_delete($user_id) {
    send_user_delete_to_rabbitmq($user_id);
}
add_action('delete_user', 'handle_user_delete', 10, 1);