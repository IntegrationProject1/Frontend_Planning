<?php
/**
 * Plugin Name: User Update Consumer
 * Description: Verwerkt gebruikersupdates van RabbitMQ op de user.
 * Version: 1.0
 * Author: Mathias Mertens
 */

require_once '/var/www/html/wp-load.php';
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

function process_user_update($xml_string) {
    $xml = simplexml_load_string($xml_string);
    if (!$xml || (string)$xml->ActionType !== 'UPDATE') return;

    $uuid = (int)$xml->UUID;

    // Zoek de gebruiker via WordPress API; hier gaan we ervan uit dat UUID gelijk is aan de user ID.
    $user = get_user_by('id', $uuid);
    if (!$user) {
        error_log("User with UUID $uuid not found.");
        return;
    }

    $userdata = ['ID' => $uuid];
    $usermeta = [];

    // Velden toewijzen op basis van tagnamen (volgens de XSD-structuur)
    $tag_map = [
        'EncryptedPassword' => 'user_pass',
        'EmailAddress'      => 'user_email',
        'FirstName'         => 'first_name',
        'LastName'          => 'last_name',
        'PhoneNumber'       => 'phone_number'
    ];

    foreach ($tag_map as $xml_field => $wp_field) {
        if (!empty($xml->$xml_field)) {
            // Bij wachtwoord en email werken we direct met de user data
            if (in_array($wp_field, ['user_email', 'user_pass'])) {
                $userdata[$wp_field] = (string)$xml->$xml_field;
            } else {
                // Overige velden slaan we op als user meta
                $usermeta[$wp_field] = (string)$xml->$xml_field;
            }
        }
    }

    // Verwerk optioneel de business-gegevens
    if (!empty($xml->Business)) {
        $business_fields = [
            'BTWNumber'         => 'btw_number',
            'RealAddress'       => 'real_address',
            'FacturationAddress'=> 'facturation_address',
            'BusinessName'      => 'business_name',
            'BusinessEmail'     => 'business_email'
        ];
        foreach ($business_fields as $xml_tag => $meta_key) {
            if (!empty($xml->Business->$xml_tag)) {
                $usermeta[$meta_key] = (string)$xml->Business->$xml_tag;
            }
        }
    }

    // Voer de update uit via de WordPress API
    wp_update_user($userdata);

    // Sla de andere velden als user meta op
    foreach ($usermeta as $meta_key => $meta_value) {
        update_user_meta($uuid, $meta_key, $meta_value);
    }

    error_log("User ID $uuid succesvol geüpdatet via RabbitMQ.");
}

add_action('init', function () {
    $host = getenv('RABBITMQ_HOST');
    $port = getenv('RABBITMQ_PORT');
    $user = getenv('RABBITMQ_USER');
    $pass = getenv('RABBITMQ_PASSWORD');

    try {
        $connection = new AMQPStreamConnection($host, $port, $user, $pass);
        $channel = $connection->channel();

        // Declareer de exchange 'user'
        $channel->exchange_declare('user', 'topic', false, true, false);

        // Declareer de queue met de juiste naam, zodat er niet wordt gezocht naar 'frontend'
        $queue_name = 'frontend_user_update';
        $channel->queue_declare($queue_name, false, true, false, false);
        $channel->queue_bind($queue_name, 'user', 'frontend.user.update');

        $callback = function ($msg) use ($channel) {
            process_user_update($msg->body);
            // ACK het bericht met de delivery tag uit het delivery_info array.
            $channel->basic_ack($msg->delivery_info['delivery_tag']);
        };

        // Start met consumeren en zorg dat de berichten niet automatisch worden ge-acknowledged.
        $channel->basic_consume($queue_name, '', false, false, false, false, $callback);

        error_log("Wachten op berichten in de queue '$queue_name'. Druk op CTRL+C om te stoppen.");

        // Houd de consumer actief door te wachten op berichten.
        while (count($channel->callbacks)) {
            $channel->wait();
        }

        // Sluit het kanaal en de verbinding (bereikt wanneer de loop stopt).
        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log("RabbitMQ Consumer Fout: " . $e->getMessage());
    }
});