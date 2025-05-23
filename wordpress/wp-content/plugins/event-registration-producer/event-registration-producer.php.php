<?php
/*
Plugin Name: Event Registration Producer
Description: Toon events en verwerk inschrijvingen met RabbitMQ & Google Calendar.
Version: 1.0
Author: Rayan Haddou
*/

add_action('init', function() {
    static $once = false;
    if ($once) {
        return;
    }
    $once = true;

    $cron = get_option('cron');
    $found = false;
    foreach ($cron as $timestamp => $hooks) {
        if (isset($hooks['rabbitmq_process_user_create'])) {
            error_log("✅ rabbitmq_process_user_create gepland voor: " . date('c', $timestamp));
            $found = true;
        }
    }
    if (!$found) {
        error_log("⚠️ rabbitmq_process_user_create NIET gevonden in WP-Cron array");
    }
});

// Laad de PHP AMQP-bibliotheek (controleer het pad naar de autoloader!)
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Voeg een custom cron-interval van één minuut toe.
 */
add_filter('cron_schedules', 'rabbitmq_add_minute_schedule');
function rabbitmq_add_minute_schedule(array $schedules) {
    // In plaats van __('Elke minuut') gebruiken we hier een gewone string, om de translate-functie niet te vroeg aan te roepen.
    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => 'Elke minuut'
    ];
    return $schedules;
}

/**
 * Plan de cron-job bij plugin-activatie.
 */
register_activation_hook(__FILE__, 'rabbitmq_activation');
function rabbitmq_activation() {
    if (!wp_next_scheduled('rabbitmq_process_user_create')) {
        wp_schedule_event(time(), 'every_minute', 'rabbitmq_process_user_create');
    }
}

/**
 * Verwijder de cron-job bij plugin-deactivatie.
 */
register_deactivation_hook(__FILE__, 'rabbitmq_deactivation');
function rabbitmq_deactivation() {
    wp_clear_scheduled_hook('rabbitmq_process_user_create');
}

/**
 * Hook voor het verwerken van RabbitMQ-berichten via WP‑Cron.
 */
add_action('rabbitmq_process_user_create', 'rabbitmq_user_create_process_cron');
function rabbitmq_user_create_process_cron() {
    // RabbitMQ-instellingen uit de omgevingsvariabelen
    $host       = getenv('RABBITMQ_HOST');
    $port       = getenv('RABBITMQ_PORT');
    $user       = getenv('RABBITMQ_USER');
    $password   = getenv('RABBITMQ_PASSWORD');
    $exchange   = 'user';
    $queue      = 'frontend_user_create';
    $routingKey = 'frontend.user.create';

    try {
        $connection = new AMQPStreamConnection($host, $port, $user, $password);
        $channel    = $connection->channel();

        // Declareer de exchange en queue.
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->queue_declare($queue, false, true, false, false);
        $channel->queue_bind($queue, $exchange, $routingKey);

        // Limiteer het aantal berichten per cyclus.
        $channel->basic_qos(null, 5, null);

        // Haal maximaal 5 berichten op via basic_get (niet-blokkerend).
        for ($i = 0; $i < 5; $i++) {
            $msg = $channel->basic_get($queue);
            if (!$msg) {
                break;
            }
            process_rabbitmq_message($msg, $channel);
        }
        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log('RabbitMQ cron fout: ' . $e->getMessage());
    }
}

/**
 * Verwerkt één ontvangen RabbitMQ-bericht.
 */
function process_rabbitmq_message(AMQPMessage $msg, $channel) {
    error_log('Bericht ontvangen via cron: ' . $msg->body);

    $xml = simplexml_load_string($msg->body);
    if (!$xml || (string)$xml->ActionType !== 'CREATE') {
        $channel->basic_ack($msg->delivery_info['delivery_tag']);
        return;
    }

    // Gegevens uitlezen.
    $email      = (string)$xml->EmailAddress;
    $firstName  = (string)$xml->FirstName;
    $lastName   = (string)$xml->LastName;
    $uuid       = (string)$xml->UUID;
    $phone      = (string)$xml->PhoneNumber;
    $pass       = (string)$xml->EncryptedPassword;

    // Controleer of de gebruiker al bestaat.
    if (get_user_by('email', $email)) {
        error_log("Gebruiker {$email} bestaat al, bericht overslaan.");
        $channel->basic_ack($msg->delivery_info['delivery_tag']);
        return;
    }

    // Schakel de producer-hook tijdelijk uit om een feedback-loop te voorkomen.
    remove_action('profile_update', 'send_user_to_rabbitmq_on_profile_update');

    // Gebruiker aanmaken.
    $userData = [
        'user_login' => $email,
        'user_pass'  => $pass,
        'user_email' => $email,
        'first_name' => $firstName,
        'last_name'  => $lastName,
    ];
    $newId = wp_insert_user($userData);
    if (is_wp_error($newId)) {
        error_log('Fout bij aanmaken gebruiker: ' . $newId->get_error_message());
    } else {
        error_log("Gebruiker aangemaakt (#{$newId})");

        // Voeg user meta toe zodat de producer later overslaat.
        update_user_meta($newId, 'synced_to_wordpress', '1');
        update_user_meta($newId, 'UUID', $uuid);
        update_user_meta($newId, 'phone_number', $phone);

        // Verwerk optionele Business-gegevens.
        if (isset($xml->Business)) {
            update_user_meta($newId, 'business_name', (string)$xml->Business->BusinessName);
            update_user_meta($newId, 'business_email', (string)$xml->Business->BusinessEmail);
            update_user_meta($newId, 'real_address', (string)$xml->Business->RealAddress);
            update_user_meta($newId, 'btw_number', (string)$xml->Business->BTWNumber);
            update_user_meta($newId, 'facturation_address', (string)$xml->Business->FacturationAddress);
        }
    }

    // Heractiveer de producer-hook zodat toekomstige profile_update events weer verwerkt worden.
    add_action('profile_update', 'send_user_to_rabbitmq_on_profile_update', 10, 1);

    // Bevestig dat het bericht is verwerkt.
    $channel->basic_ack($msg->delivery_info['delivery_tag']);
}