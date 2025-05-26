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
        <p>Evenementen worden geladen...</p>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        fetch("<?php echo admin_url('admin-ajax.php'); ?>?action=get_calendar_events")
            .then(res => res.json()
                .catch(e => {
                    console.error("❌ JSON-parserfout:", e);
                    return { success: false, data: { message: "Ongeldige JSON ontvangen." } };
                })
            )
            .then(data => {
                if (!data || data.success === false) {
                    console.error("❌ AJAX-fout:", data?.data?.message || "Lege of ongeldige reactie");
                    document.getElementById("expo-events").innerHTML = "<p>❌ Fout: " + (data?.data?.message || "Onbekend") + "</p>";
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
                        <form method="GET" action="<?php echo site_url('/evenement-detail'); ?>">
                            <input type="hidden" name="event_id" value="${event.id}">
                            <button type="submit">Inschrijven</button>
                        </form>
                    `;
                    container.appendChild(div);
                });
            })
            .catch(err => {
                console.error("❌ AJAX-verzoek fout:", err);
                document.getElementById("expo-events").innerHTML = "<p>❌ Technische fout bij ophalen van evenementen.</p>";
            });
    });
    </script>
    <?php
    return ob_get_clean();
}

// ... (le reste des fonctions reste inchangé sauf celle-ci ci-dessous)

function expo_render_event_detail() {
    if (!isset($_GET['event_id'])) {
        return "<p>⚠️ Geen evenement geselecteerd.</p>";
    }

    $eventId = sanitize_text_field($_GET['event_id']);
    $calendarId = 'planning@youmnimalha.be';

    try {
        $service = get_google_calendar_service();
        $event = $service->events->get($calendarId, $eventId);
    } catch (Exception $e) {
        return "<p>❌ Kan evenement niet ophalen: " . esc_html($e->getMessage()) . "</p>";
    }

    $user_id = get_current_user_id();
    $already_registered = false;

    if ($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'user_event';
        $already_registered = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND event_uuid = %s",
            $user_id, $eventId
        )) > 0;
}

    ob_start();
    ?>
    <div class="event-detail">
        <h2><?php echo esc_html($event->getSummary()); ?></h2>
        <p><strong>Begintijd:</strong> <?php echo esc_html($event->getStart()->getDateTime() ?? $event->getStart()->getDate()); ?></p>
        <p><strong>Eindtijd:</strong> <?php echo esc_html($event->getEnd()->getDateTime() ?? $event->getEnd()->getDate()); ?></p>
        <p><?php echo nl2br(esc_html($event->getDescription())); ?></p>

        <h3>Kies de sessies:</h3>
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="submit_session_choices">
            <input type="hidden" name="event_id" value="<?php echo esc_attr($eventId); ?>">

            <label><input type="checkbox" name="sessions[]" value="session1"> Sessie 1 - Introductie</label><br>
            <label><input type="checkbox" name="sessions[]" value="session2"> Sessie 2 - Praktijk</label><br>
            <label><input type="checkbox" name="sessions[]" value="session3"> Sessie 3 - Vragen & Antwoorden</label><br>

            <button type="submit">Bevestig inschrijving</button>
        </form>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const url = new URL(window.location.href);
        if (url.searchParams.get("confirmed") === "1") {
            alert("✅ Je bent succesvol ingeschreven voor dit evenement!");
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

add_action('admin_post_submit_session_choices', 'expo_register_event_only');

function expo_register_event_only() {
    if (!is_user_logged_in()) {
        wp_die('Je moet ingelogd zijn om je te registreren.');
    }

    if (!isset($_POST['event_id'])) {
        wp_die('Ongeldige aanvraag.');
    }

    $event_uuid = sanitize_text_field($_POST['event_id']);
    $user_id = get_current_user_id();

    global $wpdb;
    $table = $wpdb->prefix . 'user_event';

    $already = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE user_id = %d AND event_uuid = %s",
        $user_id,
        $event_uuid
    ));

    if (!$already) {
        $wpdb->insert($table, [
            'user_id' => $user_id,
            'event_uuid' => $event_uuid
        ]);
    }

    try {
        $service = get_google_calendar_service();
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
                if ($attendee->getEmail()) {
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
        $xml->addChild('EventName', $event->getSummary());
        $xml->addChild('EventDescription', $event->getDescription());
        $xml->addChild('StartDateTime', (new DateTime($event->getStart()->getDateTime() ?? $event->getStart()->getDate()))->format(DateTime::ATOM));
        $xml->addChild('EndDateTime', (new DateTime($event->getEnd()->getDateTime() ?? $event->getEnd()->getDate()))->format(DateTime::ATOM));
        $xml->addChild('EventLocation', $event->getLocation() ?: 'Onbekende locatie');
        $xml->addChild('Organisator', $event->getCreator()->getEmail() ?: 'onbekend');
        $xml->addChild('Capacity', 100);
        $xml->addChild('EventType', 'default');

        $reg = $xml->addChild('RegisteredUsers');
        foreach ($attendee_uuids as $uuid) {
            $reg->addChild('User')->addChild('UUID', $uuid);
        }

        $host = getenv('RABBITMQ_HOST');
        $port = getenv('RABBITMQ_PORT');
        $user = getenv('RABBITMQ_USER');
        $pass = getenv('RABBITMQ_PASSWORD');

        $connection = new AMQPStreamConnection($host, $port, $user, $pass);
        $channel = $connection->channel();
        $msg = new AMQPMessage($xml->asXML(), ['content_type' => 'text/xml']);
        $channel->basic_publish($msg, 'event', 'planning.event.update');
        $channel->basic_publish($msg, 'event', 'kassa.event.update');
        $channel->basic_publish($msg, 'event', 'crm.event.update');
        $channel->close();
        $connection->close();

    } catch (Exception $e) {
        error_log("❌ Fout bij verwerking van event registratie: " . $e->getMessage());
    }

    wp_redirect(site_url("/evenementen/?registration=success"));
    exit;
}

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


add_shortcode('expo_event_detail', 'expo_render_event_detail');
add_shortcode('expo_events', 'expo_render_events');

add_action('wp_ajax_get_calendar_events', 'ajax_get_calendar_events');
add_action('wp_ajax_nopriv_get_calendar_events', 'ajax_get_calendar_events');
