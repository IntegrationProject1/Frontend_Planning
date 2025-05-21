<?php
/**
 * Plugin Name: Controlroom Log Sender
 * Description: Sends formatted XML logs to the Controlroom via RabbitMQ.
 * Version: 1.0
 * Author: Attendify Team
 */

function send_controlroom_log($status, $message)
{
    $serviceName = 'frontend';
    $status = strtolower($status); // Make sure status is lowercase
    $valid_statuses = ['success', 'error', 'info', 'warning'];
    
    if (!in_array($status, $valid_statuses)) {
        error_log("❌ Invalid log status: $status");
        return;
    }

    // Add timestamp to message
    $timestamp = date('c'); // ISO 8601
    $fullMessage = "[" . $timestamp . "] " . $message;

    // Build XML string
    $xml = "<Log>";
    $xml .= "<ServiceName>" . htmlspecialchars($serviceName) . "</ServiceName>";
    $xml .= "<Status>" . htmlspecialchars($status) . "</Status>";
    $xml .= "<Message>" . htmlspecialchars($fullMessage) . "</Message>";
    $xml .= "</Log>";

    // Save to local log file for backup/debug
    $log_dir = plugin_dir_path(__FILE__) . 'controlroom-log-debug';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . '/log_debug.txt';
    file_put_contents($log_file, $xml . "\n\n", FILE_APPEND);

    // Load env variables
    $dotenv_path = dirname(__DIR__, 3) . '/.env';
    if (file_exists($dotenv_path)) {
        $lines = file($dotenv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '=') !== false) {
                list($key, $value) = explode('=', trim($line), 2);
                $_ENV[$key] = $value;
            }
        }
    }

    $host = $_ENV['RABBITMQ_HOST'] ?? 'localhost';
    $port = $_ENV['RABBITMQ_PORT'] ?? 5672;
    $user = $_ENV['RABBITMQ_USER'] ?? 'guest';
    $pass = $_ENV['RABBITMQ_PASSWORD'] ?? 'guest';
    $vhost = $_ENV['MQ_VHOST'] ?? '/';

    $exchange = 'log_monitoring';
    $routing_key = 'controlroom.log.event';

    // Retry settings
    $max_retries = 3;
    $retry_delay = 1; // seconds

    $success = false;

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        try {
            $connection = new PhpAmqpLib\Connection\AMQPStreamConnection(
                $host,
                $port,
                $user,
                $pass,
                $vhost
            );

            $channel = $connection->channel();
            $channel->exchange_declare($exchange, 'direct', false, true, false);

            $msg = new PhpAmqpLib\Message\AMQPMessage($xml, [
                'delivery_mode' => 2, // persistent
                'content_type' => 'text/xml'
            ]);

            $channel->basic_publish($msg, $exchange, $routing_key);

            $channel->close();
            $connection->close();
            error_log("✅ Controlroom log sent: [$status] $message");
            $success = true;
            break;

        } catch (Exception $e) {
            error_log("⚠️ RabbitMQ error (attempt $attempt): " . $e->getMessage());
            sleep($retry_delay);
        }
    }

    if (!$success) {
        error_log("❌ Failed to send Controlroom log after $max_retries attempts.");
    }
}

send_controlroom_log('success', '🔥 Test from LOCAL setup.');

