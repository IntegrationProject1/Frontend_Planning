<?php
/**
 * Plugin Name: RabbitMQ User Update
 * Description: Stuur alleen gewijzigde gebruikersgegevens naar RabbitMQ wanneer een gebruiker wordt bijgewerkt.
 * Version: 1.0
 * Author: Youmni Malha
 */
require_once '/var/www/html/wp-load.php';

require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function get_xml_tag_mapping() {
    return [
        'EncryptedPassword' => 'EncryptedPassword',
        'EmailAddress' => 'EmailAddress',
        'first_name' => 'FirstName',
        'last_name' => 'LastName',
        'phone_number' => 'PhoneNumber',
        'btw_number' => 'BTWNumber',
        'real_address' => 'RealAddress',
        'facturation_address' => 'FacturationAddress',
        'business_name' => 'BusinessName',
        'business_email' => 'BusinessEmail'
    ];
}

function schedule_rabbitmq_user_update($user_id, $old_user_data) {
    $new_user_data = get_userdata($user_id);
    $changes = [];

    if ($new_user_data->user_pass !== $old_user_data->user_pass) {
        $changes['EncryptedPassword'] = $new_user_data->user_pass;
    }
    if ($new_user_data->user_email !== $old_user_data->user_email) {
        $changes['EmailAddress'] = $new_user_data->user_email;
    }

    if (!empty($changes)) {
        $changes['id'] = $user_id;
        send_user_update_to_rabbitmq($changes); // Direct verzenden
        error_log('Direct RabbitMQ update for user ID: ' . $user_id . ' due to wp_users change: ' . json_encode($changes));
    }
}
add_action('profile_update', 'schedule_rabbitmq_user_update', 10, 2);

function capture_old_user_meta($meta_value, $user_id, $meta_key) {
    $tracked_meta_keys = ['first_name', 'last_name', 'btw_number', 'phone_number', 'real_address', 'facturation_address', 'business_name', 'business_email'];

    if (in_array($meta_key, $tracked_meta_keys)) {
        $old_values = get_transient('old_user_meta_' . $user_id) ?: [];
        $old_values[$meta_key] = get_user_meta($user_id, $meta_key, true);
        set_transient('old_user_meta_' . $user_id, $old_values, 60);
        error_log('Captured old value for user ID: ' . $user_id . ', meta_key: ' . $meta_key . ', old_value: ' . ($old_values[$meta_key] ?? 'null'));
    }
    return $meta_value;
}
add_filter('update_user_metadata', 'capture_old_user_meta', 10, 3);

function schedule_rabbitmq_meta_update($meta_id, $user_id, $meta_key, $meta_value) {
    $tracked_meta_keys = ['first_name', 'last_name', 'btw_number', 'phone_number', 'real_address', 'facturation_address', 'business_name', 'business_email'];

    if (in_array($meta_key, $tracked_meta_keys)) {
        $old_values = get_transient('old_user_meta_' . $user_id) ?: [];
        $old_value = isset($old_values[$meta_key]) ? $old_values[$meta_key] : '';
        $new_value = $meta_value;

        if ($old_value !== $new_value) {
            $changes = [
                'id' => $user_id,
                $meta_key => $new_value
            ];
            send_user_update_to_rabbitmq($changes); // Direct verzenden
            error_log('Direct RabbitMQ update for user ID: ' . $user_id . ' due to usermeta change: ' . json_encode($changes));
        } else {
            error_log('No change detected for user ID: ' . $user_id . ', meta_key: ' . $meta_key . ', old: ' . $old_value . ', new: ' . $new_value);
        }

        unset($old_values[$meta_key]);
        set_transient('old_user_meta_' . $user_id, $old_values, 60);
    }
}
add_action('updated_user_meta', 'schedule_rabbitmq_meta_update', 10, 4);

function send_user_update_to_rabbitmq($changes) {
    try {
        if (empty($changes) || !isset($changes['id'])) {
            throw new Exception('No valid changes to send');
        }

        $user_id = $changes['id'];
        unset($changes['id']);

        $xml = new SimpleXMLElement('<UserMessage/>');
        $xml->addChild('ActionType', 'UPDATE');
        $xml->addChild('UUID', $user_id);
        $xml->addChild('TimeOfAction', gmdate('Y-m-d\TH:i:s\Z'));

        $tag_mapping = get_xml_tag_mapping();
        $business_fields = ['btw_number', 'real_address', 'facturation_address', 'business_name', 'business_email'];
        $business_node = null;

        foreach ($changes as $key => $value) {
            $xml_key = $tag_mapping[$key] ?? $key;
            if (in_array($key, $business_fields)) {
                if (!$business_node) {
                    $business_node = $xml->addChild('Business');
                }
                $business_node->addChild($xml_key, htmlspecialchars($value));
            } else {
                $xml->addChild($xml_key, htmlspecialchars($value));
            }
        }
        $xml_string = $xml->asXML();

        $host = getenv('RABBITMQ_HOST');
        $port = getenv('RABBITMQ_PORT');
        $user = getenv('RABBITMQ_USER');
        $password = getenv('RABBITMQ_PASSWORD');
        $exchange = 'user';

        $connection = new AMQPStreamConnection($host, $port, $user, $password);
        $channel = $connection->channel();
        $channel->exchange_declare($exchange, 'topic', false, true, false);

        $queues = [
            ['name' => 'crm_user_update', 'routing_key' => 'crm.user.update'],
            ['name' => 'facturatie_user_update', 'routing_key' => 'facturatie.user.update'],
            ['name' => 'kassa_user_update', 'routing_key' => 'kassa.user.update']
        ];

        $msg = new AMQPMessage($xml_string, [
            'content_type' => 'text/xml',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);
        foreach ($queues as $queue) {
            $channel->queue_declare($queue['name'], false, true, false, false);
            $channel->queue_bind($queue['name'], $exchange, $queue['routing_key']);
            $channel->basic_publish($msg, $exchange, $queue['routing_key']);
            error_log(" [x] User update sent to {$queue['name']} with routing key {$queue['routing_key']}");
        }

        error_log(' [x] User update sent to all queues with changes: ' . json_encode($changes));
        $channel->close();
        $connection->close();
    } catch (Exception $e) {
        error_log('Error sending user update to RabbitMQ: ' . $e->getMessage());
    }
}
