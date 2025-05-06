<?php
/**
 * Plugin Name: User Create Consumer
 * Description: Ontvangt gegevens van RabbitMQ om gebruikers aan te maken.
 * Version: 1.0
 * Author: Mathias Mertens
 */

require_once '/var/www/html/wp-load.php';
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function rabbitmq_user_create_consumer() {
    // Haal de RabbitMQ settings op via environment variables.
    $host     = getenv('RABBITMQ_HOST');
    $port     = getenv('RABBITMQ_PORT');
    $user     = getenv('RABBITMQ_USER');
    $password = getenv('RABBITMQ_PASSWORD');

    $exchange    = 'user';
    $queue       = 'frontend_user_create';
    $routing_key = 'frontend.user.create';

    try {
        $connection = new AMQPStreamConnection($host, $port, $user, $password);
        $channel    = $connection->channel();

        // Declareer de exchange, queue en binding.
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->queue_declare($queue, false, true, false, false);
        $channel->queue_bind($queue, $exchange, $routing_key);

        // Callback functie voor verwerking van berichten.
        $callback = function (AMQPMessage $msg) use ($channel) {
            error_log("Bericht ontvangen: " . $msg->body);

            // Probeer de XML payload te parsen.
            $xml = simplexml_load_string($msg->body);
            if (!$xml || (string)$xml->ActionType !== 'CREATE') {
                error_log("Ongeldig XML-bericht of geen CREATE actie.");
                // Gebruik de delivery tag om het bericht te acken.
                $channel->basic_ack($msg->delivery_info['delivery_tag']);
                return;
            }

            // Verkrijg de gegevens uit de XML payload.
            $uuid          = (string)$xml->UUID;
            $encryptedPass = (string)$xml->EncryptedPassword;
            $email         = (string)$xml->EmailAddress;
            $first_name    = (string)$xml->FirstName;
            $last_name     = (string)$xml->LastName;
            $phone_number  = (string)$xml->PhoneNumber;

            // Bouw de data array voor wp_insert_user().
            $user_data = [
                'user_login' => $email,
                'user_pass'  => $encryptedPass,
                'user_email' => $email,
                'first_name' => $first_name,
                'last_name'  => $last_name
            ];

            // Controleer of de gebruiker al bestaat.
                        $is_new_user = get_user_meta($user_id, 'UUID', true) ? false : true;
                        if (!$is_new_user) {
                                return;
                        } else {
                $new_user_id = wp_insert_user($user_data);
                if (is_wp_error($new_user_id)) {
                    error_log("Fout bij het aanmaken van de gebruiker: " . $new_user_id->get_error_message());
                } else {
                    error_log("Gebruiker succesvol aangemaakt met ID: " . $new_user_id);
                    // Sla extra gegevens op als user meta.
                    update_user_meta($new_user_id, 'UUID', $uuid);
                    update_user_meta($new_user_id, 'phone_number', $phone_number);

                    // Verwerk optioneel de business data als deze aanwezig is.
                    if ($xml->Business) {
                        update_user_meta($new_user_id, 'business_name', (string)$xml->Business->BusinessName);
                        update_user_meta($new_user_id, 'business_email', (string)$xml->Business->BusinessEmail);
                        update_user_meta($new_user_id, 'real_address', (string)$xml->Business->RealAddress);
                        update_user_meta($new_user_id, 'btw_number', (string)$xml->Business->BTWNumber);
                        update_user_meta($new_user_id, 'facturation_address', (string)$xml->Business->FacturationAddress);
                    }
                }
            }

            $channel->basic_ack($msg->delivery_info['delivery_tag']);
        };

        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        error_log("Wachten op berichten in de queue '{$queue}'. Druk op CTRL+C om te stoppen.");

        while (count($channel->callbacks)) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log("RabbitMQ Consumer Fout: " . $e->getMessage());
    }
}

// Start de consumer via de 'init' hook van WordPress.
add_action('init', 'rabbitmq_user_create_consumer');