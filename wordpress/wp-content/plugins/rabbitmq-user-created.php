<?php
/**
 * Plugin Name: RabbitMQ User Create
 * Description: Stuur gebruikersgegevens naar RabbitMQ wanneer een gebruiker wordt aangemaakt.
 * Version: 1.0
 * Author: Youmni Malha & Rayan Haddou
 */
require_once '/var/www/html/wp-load.php';

// Laad de PHP AMQP bibliotheek
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Functie om gebruikersgegevens naar RabbitMQ te sturen bij profielupdate
function send_user_to_rabbitmq_on_profile_update($user_id) {
    // Controleer of dit een nieuwe gebruiker is door te kijken of de UUID al bestaat
    $is_new_user = get_user_meta($user_id, 'UUID', true) ? false : true;

    if (!$is_new_user) {
        return; // Als het geen nieuwe gebruiker is, stoppen we hier
    }

    try {
        $user_info = get_userdata($user_id);
        $user_email = $user_info->user_email;
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        $phone_number = get_user_meta($user_id, 'phone_number', true);
	$password = $user_info->user_pass;
        $btw_number = get_user_meta($user_id, 'btw_number', true);
        $business_name = get_user_meta($user_id, 'business_name', true);
        $business_email = get_user_meta($user_id, 'business_email', true);
        $real_address = get_user_meta($user_id, 'real_address', true);
        $facturation_address = get_user_meta($user_id, 'facturation_address', true);

        // Maak een XML van de gebruikersgegevens
        $xml = new SimpleXMLElement('<UserMessage/>');
        $xml->addChild('ActionType', 'CREATE');
        $xml->addChild('UUID', $user_id);
        $xml->addChild('EncryptedPassword', $password);
        $xml->addChild('TimeOfAction', gmdate('Y-m-d\TH:i:s\Z'));
        $xml->addChild('FirstName', $first_name);
        $xml->addChild('LastName', $last_name);
        $xml->addChild('PhoneNumber', $phone_number);
        $xml->addChild('EmailAddress', $user_email);

        $business = $xml->addChild('Business');
        $business->addChild('BusinessName', $business_name);
        $business->addChild('BusinessEmail', $business_email);
        $business->addChild('RealAddress', $real_address);
        $business->addChild('BTWNumber', $btw_number);
        $business->addChild('FacturationAddress', $facturation_address);
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
                'name' => 'crm_user_create',
                'routing_key' => 'crm.user.create'
            ],
            [
                'name' => 'facturatie_user_create',
                'routing_key' => 'facturatie.user.create'
            ],
            [
                'name' => 'kassa_user_create',
                'routing_key' => 'kassa.user.create'
            ]
        ];

        // Declareer en bind elke queue, verstuur het bericht
        $msg = new AMQPMessage($xml_string, ['content_type' => 'text/xml']);
        foreach ($queues as $queue) {
            $channel->queue_declare($queue['name'], false, true, false, false);
            $channel->queue_bind($queue['name'], $exchange, $queue['routing_key']);
            $channel->basic_publish($msg, $exchange, $queue['routing_key']);
            error_log(" [x] User data sent to {$queue['name']} with routing key {$queue['routing_key']}");
        }

        // Sla de UUID op als user meta
        update_user_meta($user_id, 'UUID', $user_id);

        error_log(' [x] User data sent to all queues. UUID saved as: ' . $user_id);

        // Sluit de verbinding
        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log('Error sending to RabbitMQ: ' . $e->getMessage());
    }
}
add_action('profile_update', 'send_user_to_rabbitmq_on_profile_update', 10, 1);
