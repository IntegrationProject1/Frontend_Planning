<?php
/**
 * Plugin Name: User Create Consumer
 * Description: Ontvangt gegevens van RabbitMQ om gebruikers aan te maken.
 * Version: 1.0
 * Author: Mathias Mertens
 */

// DEBUG: check WP-Cron scheduling
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
    if (! $found) {
        error_log("⚠️ rabbitmq_process_user_create NIET gevonden in WP-Cron array");
    }
});

// Laad de PHP AMQP-bibliotheek
require_once '/var/www/html/vendor/autoload.php';

/**
 * Voeg een custom cron-interval van 1 minuut toe.
 */
add_filter('cron_schedules', 'rabbitmq_add_minute_schedule');
function rabbitmq_add_minute_schedule(array $schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => 'Elke minuut',
    ];
    return $schedules;
}

/**
 * Plan de cron-job bij plugin-activation.
 */
register_activation_hook(__FILE__, 'rabbitmq_activation');
function rabbitmq_activation() {
    if (! wp_next_scheduled('rabbitmq_process_user_create')) {
        wp_schedule_event(time(), 'every_minute', 'rabbitmq_process_user_create');
    }
}

/**
 * Verwijder de cron-job bij plugin-deactivation.
 */
register_deactivation_hook(__FILE__, 'rabbitmq_deactivation');
function rabbitmq_deactivation() {
    wp_clear_scheduled_hook('rabbitmq_process_user_create');
}

/**
 * Hook voor het verwerken van berichten via WP-Cron.
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
        $conn = new \PhpAmqpLib\Connection\AMQPStreamConnection($host, $port, $user, $password);
        $chan = $conn->channel();

        $chan->exchange_declare($exchange, 'topic', false, true, false);
        $chan->queue_declare($queue, false, true, false, false);
        $chan->queue_bind($queue, $exchange, $routingKey);

        // Limiteer prefetch tot 5 berichten
        $chan->basic_qos(null, 5, null);

        // Haal tot 5 berichten op via basic_get (niet-blocking)
        for ($i = 0; $i < 5; $i++) {
            $msg = $chan->basic_get($queue);
            if (! $msg) {
                break;
            }
            _rabbitmq_process_user_message($msg, $chan);
        }

        $chan->close();
        $conn->close();

    } catch (Exception $e) {
        error_log('RabbitMQ cron fout: ' . $e->getMessage());
    }
}

/**
 * Helper: verwerk één RabbitMQ-bericht.
 */
function _rabbitmq_process_user_message(\PhpAmqpLib\Message\AMQPMessage $msg, $channel) {
    error_log('Bericht ontvangen via cron: ' . $msg->body);

    $xml = simplexml_load_string($msg->body);
    if (! $xml || (string)$xml->ActionType !== 'CREATE') {
        $channel->basic_ack($msg->delivery_info['delivery_tag']);
        return;
    }

    // Payload uitlezen
    $email      = (string)$xml->EmailAddress;
    $first_name = (string)$xml->FirstName;
    $last_name  = (string)$xml->LastName;
    $uuid       = (string)$xml->UUID;
    $phone      = (string)$xml->PhoneNumber;
    $pass       = (string)$xml->EncryptedPassword;

    // Check of de gebruiker al bestaat (op e-mail)
    if (get_user_by('email', $email)) {
        error_log("User {$email} bestaat al, skip");
        $channel->basic_ack($msg->delivery_info['delivery_tag']);
        return;
    }

    // Tijdelijk uitschakelen van de producer-hook om feedback-loop te voorkomen
    remove_action('profile_update', 'send_user_to_rabbitmq_on_profile_update');

    // Gebruiker aanmaken
    $user_data = [
        'user_login' => $email,
        'user_pass'  => $pass,
        'user_email' => $email,
        'first_name' => $first_name,
        'last_name'  => $last_name,
    ];
    $new_id = wp_insert_user($user_data);

    if (is_wp_error($new_id)) {
        error_log('Fout bij aanmaken user: ' . $new_id->get_error_message());
    } else {
        error_log("User aangemaakt (#{$new_id})");

        // Zet de flag zodat de producer later deze gebruiker overslaat
        update_user_meta($new_id, 'synced_to_wordpress', '1');
        update_user_meta($new_id, 'UUID', $uuid);
        update_user_meta($new_id, 'phone_number', $phone);

        if ($xml->Business) {
            update_user_meta($new_id, 'business_name', (string)$xml->Business->BusinessName);
            update_user_meta($new_id, 'business_email', (string)$xml->Business->BusinessEmail);
            update_user_meta($new_id, 'real_address', (string)$xml->Business->RealAddress);
            update_user_meta($new_id, 'btw_number', (string)$xml->Business->BTWNumber);
            update_user_meta($new_id, 'facturation_address', (string)$xml->Business->FacturationAddress);
        }
    }

    // Heractiveer de producer-hook zodat toekomstige profile_update events weer verwerkt worden
    add_action('profile_update', 'send_user_to_rabbitmq_on_profile_update', 10, 1);

    // Ack het bericht zodat het niet opnieuw wordt verwerkt
    $channel->basic_ack($msg->delivery_info['delivery_tag']);
}