<?php
/**
 * Plugin Name: User Delete Consumer
 * Description: Verwijdert users op basis van RabbitMQ.
 * Version: 1.1
 * Author: Mathias Mertens
 */

 require_once plugin_dir_path(__FILE__) . '/../send-controlroom-log.php'; // Load the controlroom log sender function

// Laad de AMQP-bibliotheek
require_once ABSPATH . 'vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Voeg een cron-interval van één minuut toe
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => 'Elke minuut'
        ];
    }
    return $schedules;
});

// Definieer cron-event
define('USER_DELETE_EVENT', 'rabbitmq_process_user_delete');

// Plan cron-job bij activatie
register_activation_hook(__FILE__, 'rabbitmq_delete_activate');
function rabbitmq_delete_activate() {
    if (!wp_next_scheduled(USER_DELETE_EVENT)) {
        wp_schedule_event(time(), 'every_minute', USER_DELETE_EVENT);
    }
}

// Verwijder cron-job bij deactivatie
register_deactivation_hook(__FILE__, 'rabbitmq_delete_deactivate');
function rabbitmq_delete_deactivate() {
    wp_clear_scheduled_hook(USER_DELETE_EVENT);
}

// Hook voor het cron-event
add_action(USER_DELETE_EVENT, 'rabbitmq_user_delete_process_cron');
function rabbitmq_user_delete_process_cron() {
    $host       = getenv('RABBITMQ_HOST');
    $port       = getenv('RABBITMQ_PORT');
    $user       = getenv('RABBITMQ_USER');
    $password   = getenv('RABBITMQ_PASSWORD');
    $exchange   = 'user';
    $queue      = 'frontend_user_delete';
    $routingKey = 'frontend.user.delete';

    try {
        $connection = new AMQPStreamConnection($host, $port, $user, $password);
        $channel    = $connection->channel();

        // Declareer exchange en queue
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->queue_declare($queue, false, true, false, false);
        $channel->queue_bind($queue, $exchange, $routingKey);
        $channel->basic_qos(null, 5, null);

        // Haal maximaal 5 berichten per run
        for ($i = 0; $i < 5; $i++) {
            $msg = $channel->basic_get($queue);
            if (!$msg) {
                break;
            }

            $xml = simplexml_load_string($msg->body);
            if (!$xml || (string)$xml->ActionType !== 'DELETE') {
                $channel->basic_ack($msg->delivery_info['delivery_tag']);
                continue;
            }

            // Lees UUID en TimeOfAction
            $uuid         = (string)$xml->UUID;
            $timeOfAction = (string)$xml->TimeOfAction;

            // Zoek WP-gebruiker op basis van UUID
            $users = get_users([
                'meta_key'   => 'UUID',
                'meta_value' => $uuid,
                'number'     => 1,
                'fields'     => 'ID'
            ]);
            if (empty($users)) {
                error_log("Geen gebruiker gevonden voor DELETE met UUID {$uuid}");
                $channel->basic_ack($msg->delivery_info['delivery_tag']);
                continue;
            }
            $user_id = $users[0];

            // Zet consumer-lock om producer te laten skippen
            update_user_meta($user_id, 'rabbitmq_lock', '1');

            // Verwijder producer-hook om dubbele berichten te voorkomen
            remove_action('delete_user', 'handle_user_delete', 10, 1);

            // Zorg dat wp_delete_user() beschikbaar is
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }

            // Verwijder de gebruiker
            if (wp_delete_user($user_id, true)) {
                error_log("Gebruiker #{$user_id} verwijderd via consumer (UUID: {$uuid} op {$timeOfAction})");
                    send_controlroom_log('success', "User #{$user_id} deleted successfully (UUID: {$uuid}) at {$timeOfAction}."); // Send success log to controlroom
} else {
    error_log("Fout bij verwijderen gebruiker #{$user_id}"); // Log locally
    send_controlroom_log('error', "Failed to delete user #{$user_id} (UUID: {$uuid}) at {$timeOfAction}."); // Send error log to controlroom
}
            // Heractiveer producer-hook
            add_action('delete_user', 'handle_user_delete', 10, 1);

            // Verwijder consumer-lock
            delete_user_meta($user_id, 'rabbitmq_lock');

            // Bevestig verwerking
            $channel->basic_ack($msg->delivery_info['delivery_tag']);
        }

        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log('RabbitMQ DELETE cron fout: ' . $e->getMessage());
    }
}