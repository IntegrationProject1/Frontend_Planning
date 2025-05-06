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

function process_user_deletion($xml_string) {
    $xml = simplexml_load_string($xml_string);
    if (!$xml || (string)$xml->ActionType !== 'DELETE') {
        return;
    }

    $uuid = (int)$xml->UUID;

    // Zoek de gebruiker via de WordPress API
    $user = get_user_by('id', $uuid);
    if ($user) {
        // Zorg ervoor dat de benodigde functies beschikbaar zijn
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($uuid);
        error_log("Gebruiker verwijderd met ID: " . $uuid);
    } else {
        error_log("Geen gebruiker gevonden met UUID: " . $uuid);
    }
}

add_action('init', function () {
    // Haal de RabbitMQ instellingen op
    $host = getenv('RABBITMQ_HOST');
    $port = getenv('RABBITMQ_PORT');
    $user = getenv('RABBITMQ_USER');
    $pass = getenv('RABBITMQ_PASSWORD');

    try {
        // Maak verbinding en verkrijg het kanaal
        $connection = new AMQPStreamConnection($host, $port, $user, $pass);
        $channel = $connection->channel();

        // Declareer de exchange 'user'
        $channel->exchange_declare('user', 'topic', false, true, false);

        // Declareer expliciet de queue 'frontend_user_delete' zodat deze bestaat
        $queue_name = 'frontend_user_delete';
        $channel->queue_declare($queue_name, false, true, false, false);

        // Bind de queue aan de exchange met de juiste routing key
        $routing_key = 'frontend.user.delete';
        $channel->queue_bind($queue_name, 'user', $routing_key);

        // Callback functie voor verwerking van berichten
        $callback = function (AMQPMessage $msg) use ($channel) {
            error_log("Bericht ontvangen: " . $msg->body);
            process_user_deletion($msg->body);
            // ACK het bericht met behulp van de delivery tag
            $channel->basic_ack($msg->delivery_info['delivery_tag']);
        };

        // Start met consumeren van berichten: automatische ACK staat uit zodat wij expliciet ACK'en
        $channel->basic_consume($queue_name, '', false, false, false, false, $callback);

        error_log("Wachten op berichten in de queue '$queue_name'. Druk op CTRL+C om te stoppen.");

        // Houd de consumer actief zolang er callbacks geregistreerd zijn
        while (count($channel->callbacks)) {
            $channel->wait();
        }

        // Sluit het kanaal en de verbinding als de consumer stopt
        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log("RabbitMQ Consumer Fout (Delete): " . $e->getMessage());
    }
});