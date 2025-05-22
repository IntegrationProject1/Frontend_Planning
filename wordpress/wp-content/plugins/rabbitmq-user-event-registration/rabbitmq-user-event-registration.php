<?php
/**
 * Plugin Name: RabbitMQ Event Registration
 * Description: Stuurt inschrijvingen naar RabbitMQ en slaat ze lokaal op in de user_event-tabel.
 * Version: 1.0
 * Author: Rayan
 */

require_once '/var/www/html/wp-load.php';
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

add_action('init', function() {
    if (isset($_POST['register_event']) && is_user_logged_in()) {
        $user_id  = get_current_user_id();
        $event_id = intval($_POST['event_id']);

        // ⛔ Dubbele inschrijvingen vermijden
        global $wpdb;
        $table_name = $wpdb->prefix . 'user_event';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND event_id = %d",
            $user_id,
            $event_id
        ));

        if ($exists > 0) {
            error_log("[!] User $user_id is al ingeschreven voor event $event_id.");
            return;
        }

        // 📨 XML opstellen
        $uuid = (new DateTime())->format('Y-m-d\TH:i:s.u\Z');
        $xml  = new SimpleXMLElement('<EventRegistrationMessage/>');
        $xml->addChild('ActionType', 'REGISTER');
        $xml->addChild('UUID', $uuid);
        $xml->addChild('TimeOfAction', gmdate('Y-m-d\TH:i:s\Z'));
        $xml->addChild('UserID', $user_id);
        $xml->addChild('EventID', $event_id);
        $xml_string = $xml->asXML();

        try {
            $host = getenv('RABBITMQ_HOST');
            $port = getenv('RABBITMQ_PORT');
            $user = getenv('RABBITMQ_USER');
            $pass = getenv('RABBITMQ_PASSWORD');
            $exchange = 'event';
            $routing_key = 'frontend.event.register';
            $queue = 'frontend_event_register';

            $conn = new AMQPStreamConnection($host, $port, $user, $pass);
            $channel = $conn->channel();
            $channel->exchange_declare($exchange, 'topic', false, true, false);
            $channel->queue_declare($queue, false, true, false, false);
            $channel->queue_bind($queue, $exchange, $routing_key);

            $msg = new AMQPMessage($xml_string, ['content_type' => 'text/xml']);
            $channel->basic_publish($msg, $exchange, $routing_key);

            error_log("[+] Registratie verzonden naar RabbitMQ: user $user_id voor event $event_id");

            $channel->close();
            $conn->close();

            // 💾 Opslaan in lokale user_event-tabel
            $wpdb->insert($table_name, [
                'user_id'  => $user_id,
                'event_id' => $event_id,
            ]);

        } catch (Exception $e) {
            error_log('[!] Fout bij verzenden registratie: ' . $e->getMessage());
        }
    }
});

// 🎯 Shortcode voor frontend registratieformulier
add_shortcode('event_registration_button', function($atts) {
    $atts = shortcode_atts(['event_id' => 0], $atts);
    $event_id = intval($atts['event_id']);
    if (!is_user_logged_in()) {
        return '<p>Je moet ingelogd zijn om je in te schrijven.</p>';
    }
    return '<form method="post">
        <input type="hidden" name="event_id" value="' . esc_attr($event_id) . '" />
        <button type="submit" name="register_event">Inschrijven voor dit event</button>
    </form>';
});
