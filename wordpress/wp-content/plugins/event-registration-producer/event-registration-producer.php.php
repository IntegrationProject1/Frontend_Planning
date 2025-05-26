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


function fetch_all_calendars_and_sessions() {
    $service = get_google_calendar_service();
    $calendarList = $service->calendarList->listCalendarList();
    $allEvents = [];

    foreach ($calendarList->getItems() as $calendar) {
        $calendarId = $calendar->getId();
        $eventItems = fetch_all_events_from_calendar($calendarId);

        $allEvents[] = [
            'calendarId' => $calendarId,
            'calendarName' => $calendar->getSummary(),
            'sessions' => $eventItems
        ];
    }

    return $allEvents;
}


function expo_render_events() {
    $service = get_google_calendar_service();
    $calendarList = $service->calendarList->listCalendarList();

    ob_start();
    ?>
    <div id="expo-events">
        <h2>Evenementen</h2>
        <?php foreach ($calendarList->getItems() as $calendar): ?>
            <?php if ($calendar->getAccessRole() !== 'owner') continue; ?>
            <div class="event-box">
                <h3><?php echo esc_html($calendar->getSummary()); ?></h3>
                <form method="GET" action="<?php echo site_url('/evenement-detail'); ?>">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr($calendar->getId()); ?>">
                    <button type="submit">Bekijk details</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ... (le reste des fonctions reste inchangé sauf celle-ci ci-dessous)

function expo_render_event_detail() {
    if (!isset($_GET['event_id'])) {
        return "<p>⚠️ Geen evenement geselecteerd.</p>";
    }

    $calendarId = urldecode(sanitize_text_field($_GET['event_id']));
    $sessions = [];

    try {
        $service = get_google_calendar_service();
        $calendar = $service->calendarList->get($calendarId);
        $sessions = fetch_all_events_from_calendar($calendarId);
    } catch (Exception $e) {
        return "<p>❌ Fout bij ophalen van event of sessies: " . esc_html($e->getMessage()) . "</p>";
    }

    ob_start();
    ?>
    <div class="event-detail">
        <h2>Evenement: <?php echo esc_html($calendar->getSummary()); ?></h2>
        <p><strong>Agenda-ID:</strong> <?php echo esc_html($calendarId); ?></p>

        <h3>📅 Sessies in dit evenement:</h3>

        <?php if (empty($sessions)): ?>
            <p>⚠️ Geen sessies gevonden in deze agenda.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($sessions as $session): ?>
                    <li style="margin-bottom: 1em;">
                        <strong><?php echo esc_html($session->getSummary()); ?></strong><br>
                        📅 Start: <?php echo (new DateTime($session->getStart()->getDateTime() ?? $session->getStart()->getDate()))->format('d/m/Y H:i'); ?><br>
                        ⏰ Eind: <?php echo (new DateTime($session->getEnd()->getDateTime() ?? $session->getEnd()->getDate()))->format('d/m/Y H:i'); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
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
    try {
        $eventsByCalendar = fetch_all_calendars_and_sessions();
        $formatted = [];

        foreach ($eventsByCalendar as $calendarData) {
            $calendarId = $calendarData['calendarId'];
            $calendarName = $calendarData['calendarName'];
            $sessions = [];

            foreach ($calendarData['sessions'] as $session) {
                if (!($session instanceof Google_Service_Calendar_Event)) continue;

                $descRaw = $session->getDescription();
                $descFinal = $descRaw;
                $json = json_decode($descRaw, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($json['description'])) {
                    $descFinal = $json['description'];
                }

                $sessions[] = [
                    'id' => $session->getId(),
                    'summary' => $session->getSummary(),
                    'description' => $descFinal,
                    'start' => $session->getStart()->getDateTime() ?? $session->getStart()->getDate(),
                    'end' => $session->getEnd()->getDateTime() ?? $session->getEnd()->getDate()
                ];
            }

            $formatted[] = [
                'calendarId' => $calendarId,
                'calendarName' => $calendarName,
                'sessions' => $sessions
            ];
        }

        wp_send_json($formatted);
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => "Erreur lors de la récupération des events + sessies : " . $e->getMessage()
        ], 500);
    }
}



add_shortcode('expo_event_detail', 'expo_render_event_detail');
add_shortcode('expo_events', 'expo_render_events');

add_action('wp_ajax_get_calendar_events', 'ajax_get_calendar_events');
add_action('wp_ajax_nopriv_get_calendar_events', 'ajax_get_calendar_events');
