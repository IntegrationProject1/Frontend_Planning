<?php
if (defined('DOING_AJAX') && DOING_AJAX) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}
error_reporting(E_ALL);


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
    $client->setSubject('planning@youmnimalha.be');
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
                        <form method="GET" action="/evenement-detail">
                            <input type="hidden" name="event_id" value="${event.id}">
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
        $event = $service->events->get('planning@youmnimalha.be', $event_uuid);
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
    $calendarId = 'planning@youmnimalha.be';

    try {
        $events = fetch_all_events_from_calendar($calendarId);

        if (!is_array($events)) {
            throw new Exception("Résultat invalide : pas un tableau.");
        }

        if (empty($events)) {
            throw new Exception("Aucun événement trouvé.");
        }

        $formatted = [];
        foreach ($events as $event) {
            if (!($event instanceof Google_Service_Calendar_Event)) {
                continue;
            }

            $descRaw = $event->getDescription();
            $descFinal = $descRaw;

            // Si c’est du JSON, on essaie d’extraire la vraie description
            $json = json_decode($descRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($json['description'])) {
                $descFinal = $json['description'];
            }

            $formatted[] = [
                'id' => $event->getId(),
                'summary' => $event->getSummary(),
                'description' => $descFinal,
                'start' => $event->getStart()->getDateTime() ?? $event->getStart()->getDate(),
                'end' => $event->getEnd()->getDateTime() ?? $event->getEnd()->getDate()
            ];
        }

        wp_send_json($formatted);
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => "Erreur lors de la récupération des événements : " . $e->getMessage()
        ], 500);
    }
}


function expo_render_event_detail() {
    if (!isset($_GET['event_id'])) {
        return "<p>⚠️ Aucun événement sélectionné.</p>";
    }

    $eventId = sanitize_text_field($_GET['event_id']);
    $calendarId = 'planning@youmnimalha.be';

    try {
        $service = get_google_calendar_service();
        $event = $service->events->get($calendarId, $eventId);
    } catch (Exception $e) {
        return "<p>❌ Impossible de récupérer l’événement : " . esc_html($e->getMessage()) . "</p>";
    }

    ob_start();
    ?>
    <div class="event-detail">
        <h2><?php echo esc_html($event->getSummary()); ?></h2>
        <p><strong>Début :</strong> <?php echo esc_html($event->getStart()->getDateTime() ?? $event->getStart()->getDate()); ?></p>
        <p><strong>Fin :</strong> <?php echo esc_html($event->getEnd()->getDateTime() ?? $event->getEnd()->getDate()); ?></p>
        <p><?php echo nl2br(esc_html($event->getDescription())); ?></p>

        <h3>Choisissez les sessions :</h3>
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="submit_session_choices">
            <input type="hidden" name="event_id" value="<?php echo esc_attr($eventId); ?>">

            <label><input type="checkbox" name="sessions[]" value="session1"> Session 1 - Introduction</label><br>
            <label><input type="checkbox" name="sessions[]" value="session2"> Session 2 - Pratique</label><br>
            <label><input type="checkbox" name="sessions[]" value="session3"> Session 3 - Q&A</label><br>

            <button type="submit">Valider l'inscription</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('expo_event_detail', 'expo_render_event_detail');



add_shortcode('expo_events', 'expo_render_events');
