<?php
/**
 * Plugin Name: Event Registration Producer
 * Description: Toon events en verwerk inschrijvingen met RabbitMQ & Google Calendar.
 * Version: 1.1
 * Author: Rayan Haddou
 */

if (file_exists('/var/www/html/vendor/autoload.php')) {
    require_once '/var/www/html/vendor/autoload.php';
}

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function get_google_calendar_service() {
    $client = new \Google_Client();
    $client->setAuthConfig('/var/www/html/wp-content/credentials/calendar-service-account.json');
    $client->setScopes(['https://www.googleapis.com/auth/calendar']);
    $client->setSubject('frontend@youmnimalha.be');
    return new Google_Service_Calendar($client);
}

function fetch_all_events_from_calendar($calendarId) {
    $service = get_google_calendar_service();
    $allEvents = [];
    $pageToken = null;

    do {
        $params = [
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'timeMin' => (new DateTime('2000-01-01'))->format(DateTime::RFC3339),
            'pageToken' => $pageToken
        ];

        $events = $service->events->listEvents($calendarId, $params);
        $allEvents = array_merge($allEvents, $events->getItems());
        $pageToken = $events->getNextPageToken();
    } while ($pageToken);

    return $allEvents;
}

function expo_render_events() {
    ob_start();
    ?>
    <div id="expo-events">
        <p>Evenementen laden...</p>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        fetch("<?php echo admin_url('admin-ajax.php'); ?>?action=get_calendar_events")
            .then(res => res.json()
                .catch(e => {
                    console.error("❌ JSON parsing failed:", e);
                    return { success: false, data: { message: "Invalide JSON reçu." } };
                })
            )
            .then(data => {
                if (!data || data.success === false) {
                    console.error("❌ Erreur AJAX:", data?.data?.message || "Réponse vide ou invalide");
                    document.getElementById("expo-events").innerHTML = "<p>❌ Erreur: " + (data?.data?.message || "Inconnue") + "</p>";
                    return;
                }

                const events = data;
                const container = document.getElementById("expo-events");
                container.innerHTML = "";
                events.forEach(event => {
                    const div = document.createElement("div");
                    div.className = "event-box";
                    div.innerHTML = `
                        <h3>${event.summary}</h3>
                        <p>${event.description || "Geen beschrijving."}</p>
                        <p><strong>Start:</strong> ${event.start}</p>
                        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="register_for_event">
                            <input type="hidden" name="event_uuid" value="${event.id}">
                            <input type="hidden" name="event_summary" value="${event.summary}">
                            <input type="hidden" name="event_description" value="${event.description}">
                            <input type="hidden" name="event_start" value="${event.start}">
                            <input type="hidden" name="event_end" value="${event.end}">
                            <button type="submit">Inschrijven</button>
                        </form>
                    `;
                    container.appendChild(div);
                });
            })
            .catch(err => {
                console.error("❌ AJAX fetch crash:", err);
                document.getElementById("expo-events").innerHTML = "<p>❌ Erreur technique lors de la requête AJAX.</p>";
            });
    });
    </script>
    <?php
    return ob_get_clean();
}

add_action('admin_post_register_for_event', 'expo_handle_event_registration');
add_action('admin_post_nopriv_register_for_event', 'expo_handle_event_registration');

function expo_handle_event_registration() {
    if (!is_user_logged_in()) {
        wp_die('Je moet ingelogd zijn om je te registreren voor een event.');
    }

    if (!isset($_POST['event_uuid'])) {
        wp_die('Ongeldige aanvraag.');
    }

    $user_id = get_current_user_id();
    $event_uuid = sanitize_text_field($_POST['event_uuid']);
    $event_start = sanitize_text_field($_POST['event_start']);
    $event_end = sanitize_text_field($_POST['event_end']);

    global $wpdb;
    $table = $wpdb->prefix . 'user_event';

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE user_id = %d AND event_uuid = %s",
        $user_id,
        $event_uuid
    ));

    if ($existing == 0) {
        $wpdb->insert($table, [
            'user_id' => $user_id,
            'event_uuid' => $event_uuid
        ]);
    }

    $service = get_google_calendar_service();

    try {
        $event = $service->events->get('frontend@youmnimalha.be', $event_uuid);
        $attendees = $event->getAttendees();
        $user_uuid = get_user_meta($user_id, 'UUID', true);
        if (!$user_uuid) {
            $user_uuid = (new DateTime())->format('Y-m-d\TH:i:s.u\Z');
            update_user_meta($user_id, 'UUID', $user_uuid);
        }
        $attendee_uuids = [$user_uuid];

        if ($attendees) {
            foreach ($attendees as $attendee) {
                if (!empty($attendee->getEmail())) {
                    $wp_user = get_user_by('email', $attendee->getEmail());
                    if ($wp_user) {
                        $uuid = get_user_meta($wp_user->ID, 'UUID', true);
                        if ($uuid && $uuid !== $user_uuid) {
                            $attendee_uuids[] = $uuid;
                        }
                    }
                }
            }
        }

        $xml = new SimpleXMLElement('<UpdateEvent/>');
        $xml->addChild('EventUUID', $event_uuid);
        $xml->addChild('EventName', sanitize_text_field($_POST['event_summary']));
        $xml->addChild('EventDescription', sanitize_text_field($_POST['event_description']));
        $xml->addChild('StartDateTime', (new DateTime($event_start))->format(DateTime::ATOM));
        $xml->addChild('EndDateTime', (new DateTime($event_end))->format(DateTime::ATOM));
        $xml->addChild('EventLocation', $event->getLocation() ?: 'Onbekende locatie');
        $xml->addChild('Organisator', $event->getCreator()->getEmail() ?: 'onbekend');
        $xml->addChild('Capacity', 100);
        $xml->addChild('EventType', 'default');

        $reg = $xml->addChild('RegisteredUsers');
        foreach ($attendee_uuids as $uuid) {
            $userNode = $reg->addChild('User');
            $userNode->addChild('UUID', $uuid);
        }

        try {
            $host     = getenv('RABBITMQ_HOST');
            $port     = getenv('RABBITMQ_PORT');
            $user     = getenv('RABBITMQ_USER');
            $password = getenv('RABBITMQ_PASSWORD');
            $exchange = 'event';
            $routing  = 'planning.event.update';

            $connection = new AMQPStreamConnection($host, $port, $user, $password);
            $channel    = $connection->channel();

            $msg = new AMQPMessage($xml->asXML(), ['content_type' => 'text/xml']);
            $channel->basic_publish($msg, $exchange, $routing);

            error_log("✅ Event registratie verstuurd naar RabbitMQ: {$event_uuid}");

            $channel->close();
            $connection->close();
        } catch (Exception $e) {
            error_log("❌ RabbitMQ fout: " . $e->getMessage());
        }

    } catch (Exception $e) {
        error_log("❌ Fout bij ophalen van Google Calendar event: " . $e->getMessage());
        wp_die('Er ging iets mis bij het ophalen van het event.');
    }

    wp_redirect($_SERVER['HTTP_REFERER'] . '?registration=success');
    exit;
}

add_action('wp_ajax_get_calendar_events', 'ajax_get_calendar_events');
add_action('wp_ajax_nopriv_get_calendar_events', 'ajax_get_calendar_events');

function ajax_get_calendar_events() {
    error_log("🎯 Début AJAX get_calendar_events");
    $calendarId = 'frontend@youmnimalha.be';

    try {
        $events = fetch_all_events_from_calendar($calendarId);

        if (!is_array($events)) {
            throw new Exception("Le résultat de fetch_all_events_from_calendar n'est pas un tableau.");
        }

        if (empty($events)) {
            throw new Exception("Aucun événement trouvé dans Google Calendar.");
        }

        $formatted = [];
        foreach ($events as $event) {
            if (!($event instanceof Google_Service_Calendar_Event)) {
                error_log("⚠️ Un élément retourné n'est pas une instance valide de Google_Service_Calendar_Event");
                continue;
            }

            $formatted[] = [
                'id' => $event->getId(),
                'summary' => $event->getSummary(),
                'description' => $event->getDescription(),
                'start' => $event->getStart()->getDateTime() ?? $event->getStart()->getDate(),
                'end' => $event->getEnd()->getDateTime() ?? $event->getEnd()->getDate()
            ];
        }

        wp_send_json($formatted);
    } catch (Exception $e) {
        $error_message = "❌ AJAX get_calendar_events error: " . $e->getMessage();
        error_log($error_message);

        // ➕ Nouvelle réponse avec trace complète
        wp_send_json_error([
            'message' => $error_message,
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}



add_shortcode('expo_events', 'expo_render_events');
