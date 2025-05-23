<?php
/**
 * Plugin Name: User Update Consumer
 * Description: Verwerkt gebruikersupdates van RabbitMQ op de user.
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
define('USER_UPDATE_EVENT', 'rabbitmq_process_user_update');

// Plan en verwijder cron-job
register_activation_hook  (__FILE__, 'rabbitmq_update_activate');
register_deactivation_hook(__FILE__, 'rabbitmq_update_deactivate');
function rabbitmq_update_activate() {
    if (!wp_next_scheduled(USER_UPDATE_EVENT)) {
        wp_schedule_event(time(), 'every_minute', USER_UPDATE_EVENT);
    }
}
function rabbitmq_update_deactivate() {
    wp_clear_scheduled_hook(USER_UPDATE_EVENT);
}

// Hook voor het cron-event
add_action(USER_UPDATE_EVENT, 'rabbitmq_user_update_process_cron');
function rabbitmq_user_update_process_cron() {
    $host       = getenv('RABBITMQ_HOST');
    $port       = getenv('RABBITMQ_PORT');
    $user       = getenv('RABBITMQ_USER');
    $password   = getenv('RABBITMQ_PASSWORD');
    $exchange   = 'user';
    $queue      = 'frontend_user_update';
    $routingKey = 'frontend.user.update';

    try {
        $conn    = new AMQPStreamConnection($host, $port, $user, $password);
        $channel = $conn->channel();

        // Declareer exchange en queue
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->queue_declare($queue, false, true, false, false);
        $channel->queue_bind   ($queue, $exchange, $routingKey);
        $channel->basic_qos(null, 5, null);

        // Haal max. 5 berichten
        for ($i = 0; $i < 5; $i++) {
            $msg = $channel->basic_get($queue);
            if (!$msg) break;

            $xml = simplexml_load_string($msg->body);
            if (!$xml || (string)$xml->ActionType !== 'UPDATE') {
                $channel->basic_ack($msg->delivery_info['delivery_tag']);
                continue;
            }

            // UUID & timestamp
            $uuid         = (string)$xml->UUID;
            $timeOfAction = (string)$xml->TimeOfAction;

            // Vind WP-user op UUID
            $users = get_users([
                'meta_key'   => 'UUID',
                'meta_value' => $uuid,
                'number'     => 1,
                'fields'     => 'ID'
            ]);
            if (empty($users)) {
                error_log("Geen gebruiker gevonden voor UPDATE met UUID {$uuid}");
                $channel->basic_ack($msg->delivery_info['delivery_tag']);
                continue;
            }
            $user_id = $users[0];

            // ——— Lock zetten voor producer ———
            update_user_meta($user_id, 'rabbitmq_lock', '1');

            // Schakel producer-hooks uit
            remove_action('profile_update',   'schedule_rabbitmq_user_update', 10, 2);
            remove_action('updated_user_meta','schedule_rabbitmq_meta_update',   10, 4);

            // Bouw update-array
            $update_data = ['ID' => $user_id];
            if (isset($xml->FirstName))    $update_data['first_name'] = (string)$xml->FirstName;
            if (isset($xml->LastName))     $update_data['last_name']  = (string)$xml->LastName;
            if (isset($xml->EmailAddress)) $update_data['user_email'] = (string)$xml->EmailAddress;
            if (isset($xml->EncryptedPassword)) {
                $update_data['user_pass'] = (string)$xml->EncryptedPassword;
            }

            // Voer WP‑update uit
            $result = wp_update_user($update_data);
           if (is_wp_error($result)) {
    $errorMessage = $result->get_error_message(); // Get error message
    error_log("Fout bij update gebruiker #{$user_id}: " . $errorMessage); // Log locally
    send_controlroom_log('error', "Failed to update user #{$user_id} (UUID: {$uuid}): " . $errorMessage); // Send error log

            } else {
                error_log("Gebruiker #{$user_id} bijgewerkt (UUID: {$uuid} op {$timeOfAction})");
send_controlroom_log('success', "User #{$user_id} updated successfully (UUID: {$uuid}) at {$timeOfAction}.");// Send success log

                // Optionele meta
                if (isset($xml->PhoneNumber)) {
                    update_user_meta($user_id, 'phone_number', (string)$xml->PhoneNumber);
                }
                if (isset($xml->Business)) {
                    update_user_meta($user_id, 'business_name',       (string)$xml->Business->BusinessName);
                    update_user_meta($user_id, 'business_email',      (string)$xml->Business->BusinessEmail);
                    update_user_meta($user_id, 'real_address',        (string)$xml->Business->RealAddress);
                    update_user_meta($user_id, 'btw_number',          (string)$xml->Business->BTWNumber);
                    update_user_meta($user_id, 'facturation_address', (string)$xml->Business->FacturationAddress);
                }
            }

            // Heractiveer producer-hooks
            add_action('profile_update',    'schedule_rabbitmq_user_update', 10, 2);
            add_action('updated_user_meta', 'schedule_rabbitmq_meta_update',   10, 4);

            // Verwijder de lock
            delete_user_meta($user_id, 'rabbitmq_lock');

            // Ack bericht
            $channel->basic_ack($msg->delivery_info['delivery_tag']);
        }

        $channel->close();
        $conn->close();
    } catch (Exception $e) {
        error_log('RabbitMQ UPDATE cron fout: ' . $e->getMessage());
    }
}