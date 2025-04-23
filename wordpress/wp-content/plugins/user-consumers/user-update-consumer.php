<?php
/**
 * Plugin Name: User Update Consumer
 * Description: Verwerkt gebruikersupdates van RabbitMQ op de user.
 * Version: 1.1
 * Author: Mathias Mertens
 */

require_once '/var/www/html/wp-load.php';
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Verwerkt een UPDATE-bericht en werkt de user bij.
 */
function process_user_update($xml_string) {
    $xml = simplexml_load_string($xml_string);
    if (!$xml || (string)$xml->ActionType !== 'UPDATE') {
        return;
    }

    // Parse de UUID als xs:dateTime met microseconden
    try {
        $dtUuid = \DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', (string) $xml->UUID);
        if (! $dtUuid) {
            throw new \Exception('Format mismatch');
        }
        $uuidString = $dtUuid->format('Y-m-d\TH:i:s.v\Z');
    } catch (\Exception $e) {
        error_log("Ongeldig UUID-datumformaat in update: " . $e->getMessage());
        return;
    }

    // Zoek de gebruiker via meta 'UUID'
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

    // Bereid WP-update data
    $userdata = ['ID' => $user_id];
    $usermeta = [];

    // Veldmapping XML => WP
    $tag_map = [
        'EncryptedPassword' => 'user_pass',
        'EmailAddress'      => 'user_email',
        'FirstName'         => 'first_name',
        'LastName'          => 'last_name',
        'PhoneNumber'       => 'phone_number',
    ];

    foreach ($tag_map as $xml_field => $wp_field) {
        if (!empty($xml->$xml_field)) {
            if (in_array($wp_field, ['user_email', 'user_pass'])) {
                $userdata[$wp_field] = (string) $xml->$xml_field;
            } else {
                $usermeta[$wp_field] = (string) $xml->$xml_field;
            }
        }
    }

    // Optioneel TimeOfAction opslaan
    if (!empty($xml->TimeOfAction)) {
        try {
            $dtAction = new \DateTime((string) $xml->TimeOfAction);
            $timeOfAction = $dtAction->format('Y-m-d\TH:i:s\Z');
            $usermeta['TimeOfAction'] = $timeOfAction;
        } catch (\Exception $e) {
            error_log("Ongeldig TimeOfAction in update: " . $e->getMessage());
        }
    }

    // Verwerk business data
    if (!empty($xml->Business)) {
        $business_fields = [
            'BusinessName'      => 'business_name',
            'BusinessEmail'     => 'business_email',
            'RealAddress'       => 'real_address',
            'BTWNumber'         => 'btw_number',
            'FacturationAddress'=> 'facturation_address',
        ];
        foreach ($business_fields as $xml_tag => $meta_key) {
            if (!empty($xml->Business->$xml_tag)) {
                $usermeta[$meta_key] = (string) $xml->Business->$xml_tag;
            }
        }
    }

    // Voer de update uit
    wp_update_user($userdata);
    foreach ($usermeta as $meta_key => $meta_value) {
        update_user_meta($user_id, $meta_key, $meta_value);
    }

    error_log("Gebruiker succesvol geüpdatet met user_id: {$user_id}");
}

add_action('init', function () {
    // RabbitMQ instellingen
    $host = getenv('RABBITMQ_HOST');
    $port = getenv('RABBITMQ_PORT');
    $user = getenv('RABBITMQ_USER');
    $pass = getenv('RABBITMQ_PASSWORD');

    $exchange    = 'user';
    $queue_name  = 'frontend_user_update';
    $routing_key = 'frontend.user.update';

    try {
        $connection = new AMQPStreamConnection($host, $port, $user, $pass);
        $channel    = $connection->channel();

        // Declareer exchange en queue
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->queue_declare($queue_name, false, true, false, false);
        $channel->queue_bind($queue_name, $exchange, $routing_key);

        // Callback voor update
        $callback = function (AMQPMessage $msg) use ($channel) {
            error_log("Update-bericht ontvangen: " . $msg->body);
            process_user_update($msg->body);
            $channel->basic_ack($msg->delivery_info['delivery_tag']);
        };

        $channel->basic_consume($queue_name, '', false, false, false, false, $callback);

        error_log("Wachten op update-berichten in queue '{$queue_name}'...");
        while (count($channel->callbacks)) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log("RabbitMQ Update Consumer Fout: " . $e->getMessage());
    }
});