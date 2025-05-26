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
        if ( $calendar->getId() === 'planning@youmnimalha.be' ) {
        continue;
    }
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

    <?php if (isset($_GET['already_registered']) && $_GET['already_registered'] == 1): ?>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            alert("You are already registered for this event.");
        });
    </script>
    <?php endif; ?>

    <div id="expo-events">
        <?php foreach ($calendarList->getItems() as $calendar): 
            if ( $calendar->getId() === 'planning@youmnimalha.be' ) {
          continue;
        }
        ?>
            <?php if ($calendar->getAccessRole() !== 'owner') continue; ?>
            <div class="event-box">
                <h3><?php echo esc_html($calendar->getSummary()); ?></h3>
                <form method="GET" action="<?php echo site_url('/event-details'); ?>">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr($calendar->getId()); ?>">
                    <button type="submit">View details</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}




function expo_render_event_detail() {
    if ( ! isset( $_GET['event_id'] ) ) {
        return '<p>⚠️ No event selected.</p>';
    }

    $calendarId = sanitize_text_field( $_GET['event_id'] );
    $service    = get_google_calendar_service();

    try {
        $calendar = $service->calendarList->get( $calendarId );
    } catch (Exception $e) {
        return '<p>❌ Unable to load event details.</p>';
    }
    $title = $calendar->getSummary();

    $sessions = fetch_all_events_from_calendar( $calendarId );
    $first    = reset( $sessions );
    if ( $first ) {
        $start = ( new DateTime( $first->getStart()->getDateTime() ?? $first->getStart()->getDate() ) )
                 ->format( 'Y-m-d H:i' );
        $loc   = $first->getLocation() ?: 'TBD';
    } else {
        $start = $loc = 'TBD';
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $is_event_reg = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}user_event WHERE user_id=%d AND event_uuid=%s",
        $user_id, $calendarId
    ) );
    $registered_sessions = $wpdb->get_col( $wpdb->prepare(
        "SELECT session_id FROM {$wpdb->prefix}user_session WHERE user_id=%d AND event_uuid=%s",
        $user_id, $calendarId
    ) );

    ob_start(); ?>
    <div class="event-detail">
      <h2 class="event-title"><?= esc_html( $title ) ?></h2>

      <div class="event-detail__grid">
        <div class="sessions-list">
          <h3>Sessions</h3>
          <?php if ( $is_event_reg ): ?>
            <p><em>You are already registered for this event.</em></p>
          <?php endif; ?>

          <form method="POST" action="<?= esc_url( admin_url('admin-post.php') ) ?>">
            <input type="hidden" name="action"   value="submit_session_choices">
            <input type="hidden" name="event_id" value="<?= esc_attr( $calendarId ) ?>">

            <?php foreach ( $sessions as $session ) :
              $sid      = $session->getId();
              $disabled = in_array( $sid, $registered_sessions )
                          ? 'disabled title="Already registered"'
                          : '';
            ?>
              <label>
                <input type="checkbox"
                       name="sessions[]"
                       value="<?= esc_attr( $sid ) ?>"
                       <?= $disabled ?>>
                <strong><?= esc_html( $session->getSummary() ) ?></strong>
                — <?= esc_html( (new DateTime(
                        $session->getStart()->getDateTime()
                        ?? $session->getStart()->getDate()
                      ))->format('Y-m-d H:i') ) ?>
              </label><br><br>
            <?php endforeach; ?>
          </form>
        </div>

        <div class="event-info">
          <p><strong>Date:</strong> <?= esc_html( $start ) ?></p>
          <p><strong>Location:</strong> <?= esc_html( $loc ) ?></p>
        </div>
      </div>

      <div class="event-actions">
        <form method="POST" action="<?= esc_url( admin_url('admin-post.php') ) ?>">
          <input type="hidden" name="action"   value="submit_session_choices">
          <input type="hidden" name="event_id" value="<?= esc_attr( $calendarId ) ?>">
          <button type="submit">
            <?= $is_event_reg ? 'Update my sessions' : 'Register for event' ?>
          </button>
        </form>
      </div>
    </div>
    <?php
    return ob_get_clean();
}







add_action('admin_post_submit_session_choices', 'expo_register_event_only');
add_action('admin_post_nopriv_submit_session_choices', 'expo_register_event_only');

function expo_register_event_only() {
    global $wpdb;
    if (!is_user_logged_in()) {
        wp_die('You must be logged in to register.');
    }

    if (!isset($_POST['event_id'])) {
        wp_die('Invalid request.');
    }

    $event_uuid = sanitize_text_field($_POST['event_id']);
    $user_id = get_current_user_id();
    $sessions = isset($_POST['sessions']) ? array_map('sanitize_text_field', $_POST['sessions']) : [];

    $already_event = (bool) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}user_event WHERE user_id=%d AND event_uuid=%s",
            $user_id, $event_uuid
        )
    );
    if ($already_event && empty($sessions)) {

        wp_redirect(site_url("/events/?already_registered=1"));
        exit;
    }

    if (! $already_event) {
        $wpdb->insert(
            "{$wpdb->prefix}user_event",
            [ 'user_id' => $user_id, 'event_uuid' => $event_uuid ]
        );
    }


    $host = getenv('RABBITMQ_HOST');
        $port = getenv('RABBITMQ_PORT');
        $user = getenv('RABBITMQ_USER');
        $pass = getenv('RABBITMQ_PASSWORD');

        $connection = new AMQPStreamConnection($host, $port, $user, $pass);
        $channel = $connection->channel();

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

        $msg = new AMQPMessage($xml->asXML(), ['content_type' => 'text/xml']);
        $channel->basic_publish($msg, 'event', 'planning.event.update');
        $channel->basic_publish($msg, 'event', 'kassa.event.update');
        $channel->basic_publish($msg, 'event', 'crm.event.update');
        

    } catch (Exception $e) {
        error_log("❌ Error processing event registration: " . $e->getMessage());
    }

    $session_table = $wpdb->prefix . 'user_session';


    foreach ( $sessions as $session_id ) {
        $already_session = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}user_session WHERE user_id=%d AND event_uuid=%s AND session_id=%s",
            $user_id, $event_uuid, $session_id
        ) );
        if ( ! $already_session ) {
            $wpdb->insert(
                "{$wpdb->prefix}user_session",
                [
                'user_id'    => $user_id,
                'event_uuid' => $event_uuid,
                'session_id' => $session_id
                ]
            );
        }
    }



    foreach ($sessions as $session_id) {
    try {
        $session = $service->events->get($event_uuid, $session_id);

        $xml = new SimpleXMLElement('<UpdateSession/>');
        $xml->addChild('SessionUUID', (new DateTime())->format(DateTime::ATOM));
        $xml->addChild('EventUUID', $event_uuid);
        $xml->addChild('SessionName', $session->getSummary());
        $xml->addChild('SessionDescription', $session->getDescription() ?? '');

        // Optional: guest speakers
        $guest = $xml->addChild('GuestSpeakers');
        $guest_speaker = $guest->addChild('GuestSpeaker');
        $guest_speaker->addChild('email', $session->getCreator()->getEmail() ?? '');

        $xml->addChild('Capacity', 100);
        $xml->addChild('StartDateTime', (new DateTime($session->getStart()->getDateTime() ?? $session->getStart()->getDate()))->format(DateTime::ATOM));
        $xml->addChild('EndDateTime', (new DateTime($session->getEnd()->getDateTime() ?? $session->getEnd()->getDate()))->format(DateTime::ATOM));
        $xml->addChild('SessionLocation', $session->getLocation() ?? 'Onbekende locatie');
        $xml->addChild('SessionType', 'default');

        $reg = $xml->addChild('RegisteredUsers');
        $user = $reg->addChild('User');
        $user->addChild('email', wp_get_current_user()->user_email);

        $msg = new AMQPMessage($xml->asXML(), ['content_type' => 'text/xml']);

        // Publish to both routing keys
        $channel->basic_publish($msg, 'session', 'planning.session.update');
        $channel->basic_publish($msg, 'session', 'crm.session.update');

    } catch (Exception $ex) {
        error_log("❌ Error processing session ($session_id): " . $ex->getMessage());
    }
}

        $channel->close();
        $connection->close();

    wp_redirect(site_url("/events/?registration=success"));
    exit;
}

function ajax_get_calendar_events() {
    try {
        $eventsByCalendar = fetch_all_calendars_and_sessions();
        $formatted = [];

        foreach ( $eventsByCalendar as $calendarData ) {
            // On skip l'agenda “planning@youmnimalha.be”
            if ( $calendarData['calendarId'] === 'planning@youmnimalha.be' ) {
                continue;
            }

            $sessions = [];
            foreach ( $calendarData['sessions'] as $session ) {
                if ( ! ($session instanceof Google_Service_Calendar_Event) ) {
                    continue;
                }
                // Gestion description JSON vs. texte
                $descRaw   = $session->getDescription();
                $descFinal = $descRaw;
                $json      = json_decode( $descRaw, true );
                if ( json_last_error() === JSON_ERROR_NONE && isset( $json['description'] ) ) {
                    $descFinal = $json['description'];
                }

                $sessions[] = [
                    'id'          => $session->getId(),
                    'summary'     => $session->getSummary(),
                    'description' => $descFinal,
                    'start'       => $session->getStart()->getDateTime() ?? $session->getStart()->getDate(),
                    'end'         => $session->getEnd()->getDateTime()   ?? $session->getEnd()->getDate(),
                ];
            }

            $formatted[] = [
                'calendarId'   => $calendarData['calendarId'],
                'calendarName' => $calendarData['calendarName'],
                'sessions'     => $sessions,
            ];
        }

        wp_send_json( $formatted );
    } catch ( Exception $e ) {
        wp_send_json_error([
            'message' => "Erreur lors de la récupération des events + sessions : " . $e->getMessage()
        ], 500);
    }
}




add_shortcode('expo_event_detail', 'expo_render_event_detail');
add_shortcode('expo_events', 'expo_render_events');

add_action('wp_ajax_get_calendar_events', 'ajax_get_calendar_events');
add_action('wp_ajax_nopriv_get_calendar_events', 'ajax_get_calendar_events');

/**
 * Enqueue grid CSS for two-columns layout on the Events page
 */
add_action( 'wp_enqueue_scripts', function(){
    wp_register_style( 'expo-events-css', false );
    wp_enqueue_style( 'expo-events-css' );
    wp_add_inline_style( 'expo-events-css', "
        #expo-events {
          display: grid;
          grid-template-columns: repeat(2,1fr);
          grid-gap: 2rem;
          justify-items: center;
          padding: 2rem;
        }
        .event-box {
          max-width: 350px;
          width: 100%;
          background: #f5f5f5;
          padding: 1.5rem;
          border-radius: .5rem;
          text-align: center;
          box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .event-box h3 {
          margin-bottom: 1rem;
          font-size: 1.25rem;
        }
        .event-box button {
          padding: .75rem 1.5rem;
          border: none;
          background: #0073aa;
          color: #fff;
          font-weight: bold;
          border-radius: .25rem;
          cursor: pointer;
        }
        .event-box button:hover {
          background: #005177;
        }
        @media (max-width: 768px) {
          #expo-events {
            grid-template-columns: 1fr;
          }
        }

                
        .event-detail {
            max-width: 800px;
            margin: 0 auto;
            padding: 1rem;
            }
            .event-title {
            text-align: center;
            margin: 1rem 0 2rem; /* Titre un peu plus haut */
            }
            .event-detail__grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            grid-gap: 2rem;
            align-items: start;
            }
            /* Sessions à gauche */
            .sessions-list h3 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            }
            .sessions-list {
            /* remonte la liste pour être plus près du titre */
            margin-top: 0;
            }
            /* Infos à droite */
            .event-info {
            background: #f5f5f5;
            padding: 1rem;
            border-radius: .5rem;
            box-shadow: 0 1px 6px rgba(0,0,0,0.1);
            }
            .event-info p {
            margin: .5rem 0;
            }
            /* Bouton centré en bas */
            .event-actions {
            text-align: center;
            margin-top: 2rem;
            }
            .event-actions button {
            padding: .75rem 1.5rem;
            border: none;
            background: #0073aa;
            color: #fff;
            font-weight: bold;
            border-radius: .25rem;
            cursor: pointer;
            }
            .event-actions button:hover {
            background: #005177;
            }
            /* Responsive : une colonne sur mobile */
            @media (max-width: 768px) {
            .event-detail__grid {
                grid-template-columns: 1fr;
            }
            .sessions-list {
                order: -1; /* sessions d’abord */
                margin-bottom: 1.5rem;
            }
            }


    " );
}, 20 );