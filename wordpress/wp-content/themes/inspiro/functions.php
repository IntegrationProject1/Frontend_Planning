<?php
/**
 * Inspiro functions and definitions
 *
 * @link    https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Inspiro
 * @since   Inspiro 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Define Constants
 */
define( 'INSPIRO_THEME_VERSION', '2.0.3' );
define( 'INSPIRO_THEME_DIR', trailingslashit( get_template_directory() ) );
define( 'INSPIRO_THEME_URI', trailingslashit( esc_url( get_template_directory_uri() ) ) );
define( 'INSPIRO_THEME_ASSETS_URI', INSPIRO_THEME_URI . 'dist' );
// Marketing
define( 'INSPIRO_MARKETING_UTM_CODE_STARTER_SITE', '?utm_source=wpadmin&utm_medium=starter-sites&utm_campaign=upgrade-premium' );
define( 'INSPIRO_MARKETING_UTM_CODE_FOOTER_MENU', '?utm_source=wpadmin&utm_medium=footer-menu&utm_campaign=upgrade-premium' );

// This theme requires WordPress 5.3 or later.
if ( version_compare( $GLOBALS['wp_version'], '5.3', '<' ) ) {
	require INSPIRO_THEME_DIR . 'inc/back-compat.php';
}

/**
 * Recommended Plugins
 */
require INSPIRO_THEME_DIR . 'inc/classes/class-tgm-plugin-activation.php';

/**
 * Setup helper functions.
 */
require INSPIRO_THEME_DIR . 'inc/common-functions.php';

/**
 * Setup theme media.
 */
require INSPIRO_THEME_DIR . 'inc/theme-media.php';

/**
 * Enqueues scripts and styles
 */
require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-enqueue-scripts.php';

/**
 * Setup custom wp-admin options pages
 */
require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-custom-wp-admin-menu.php';

/**
 * Additional features to include custom WP pointer function
 */
require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-wp-admin-menu-pointer.php';

/**
 * Functions and definitions.
 */
require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-after-setup-theme.php';

/**
 * Handle SVG icons.
 */
require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-svg-icons.php';

/**
 * Implement the Custom Header feature.
 */
require INSPIRO_THEME_DIR . 'inc/custom-header.php';

/**
 * Custom template tags for this theme.
 */
require INSPIRO_THEME_DIR . 'inc/template-tags.php';

/**
 * Additional features to allow styling of the templates.
 */
require INSPIRO_THEME_DIR . 'inc/template-functions.php';

/**
 * Custom Template WC functions
 */
require INSPIRO_THEME_DIR . 'inc/wc-custom-functions.php';



/**
 * Custom template shortcode tags for this theme
 */
// require INSPIRO_THEME_DIR . 'inc/shortcodes.php';

/**
 * Customizer additions.
 */
require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-font-family-manager.php';
require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-fonts-manager.php';

// Include Customizer Guided Tour
if ( is_admin() ) { // && is_customize_preview(), AJAX don't work with is_customize_preview() included
	require INSPIRO_THEME_DIR . 'inc/classes/inspiro-customizer-guided-tour.php';
}
require INSPIRO_THEME_DIR . 'inc/customizer-functions.php';
require INSPIRO_THEME_DIR . 'inc/customizer/class-inspiro-customizer-control-base.php';
require INSPIRO_THEME_DIR . 'inc/customizer/class-inspiro-customizer.php';

/**
 * SVG icons functions and filters.
 */
require INSPIRO_THEME_DIR . 'inc/icon-functions.php';

/**
 * Theme admin notices and info page
 */
if ( is_admin() ) {
	require INSPIRO_THEME_DIR . 'inc/admin-notice.php';
	require INSPIRO_THEME_DIR . 'inc/admin/admin-api.php';

	// temporary marketing black friday functionality
	require INSPIRO_THEME_DIR . 'inc/marketing-functions.php';

	if ( current_user_can( 'manage_options' ) ) {
		require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-notices.php';
		require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-notice-review.php';
	}
}

/**
 * Theme Upgrader
 */
require INSPIRO_THEME_DIR . 'inc/classes/class-inspiro-theme-upgrader.php';

/**
 * Inline theme css generated dynamically
 */
require INSPIRO_THEME_DIR . 'inc/dynamic-css/body.php';
require INSPIRO_THEME_DIR . 'inc/dynamic-css/logo.php';
require INSPIRO_THEME_DIR . 'inc/dynamic-css/headings.php';
require INSPIRO_THEME_DIR . 'inc/dynamic-css/hero-header-title.php';
require INSPIRO_THEME_DIR . 'inc/dynamic-css/hero-header-desc.php';
require INSPIRO_THEME_DIR . 'inc/dynamic-css/hero-header-button.php';
require INSPIRO_THEME_DIR . 'inc/dynamic-css/main-menu.php';
require INSPIRO_THEME_DIR . 'inc/dynamic-css/mobile-menu.php';

add_action('init', function () {
    if (!function_exists('expo_render_events')) {
        require_once '/var/www/html/vendor/autoload.php';
        require_once get_theme_file_path('includes/google_calendar_client.php');

        function get_google_calendar_service() {
    $client = new \Google_Client();
    $client->setAuthConfig(ABSPATH . 'wp-content/credentials/calendar-service-account.json');
    $client->setScopes(['https://www.googleapis.com/auth/calendar']);
    $client->setSubject('frontend@youmnimalha.be'); // domain-wide delegation
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
            .then(res => res.json())
            .then(events => {
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
            });
    });
    </script>
    <?php
    return ob_get_clean();
}

// 👉 Handlers buiten de functie
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
    $event = $service->events->get('frontend@youmnimalha.be', $event_uuid); // 'primary' vervangen door je echte calendarId
    $attendees = $event->getAttendees();
    // 1. Voeg ingelogde gebruiker toe aan attendeeslijst
$user_uuid = get_user_meta($user_id, 'UUID', true);
if (!$user_uuid) {
    $user_uuid = (new DateTime())->format('Y-m-d\TH:i:s.u\Z');
    update_user_meta($user_id, 'UUID', $user_uuid);
}
$attendee_uuids = [$user_uuid];

// 2. Voeg alle bestaande attendees toe (indien ze een UUID hebben in WP)
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

// 3. XML opbouwen
$xml = new SimpleXMLElement('<UpdateEvent/>');
$xml->addChild('EventUUID', $event_uuid);
$xml->addChild('EventName', sanitize_text_field($_POST['event_summary']));
$xml->addChild('EventDescription', sanitize_text_field($_POST['event_description']));
$xml->addChild('StartDateTime', (new DateTime($event_start))->format(DateTime::ATOM));
$xml->addChild('EndDateTime', (new DateTime($event_end))->format(DateTime::ATOM));
$xml->addChild('EventLocation', $event->getLocation() ?: 'Onbekende locatie');
$xml->addChild('Organisator', $event->getCreator()->getEmail() ?: 'onbekend');
$xml->addChild('Capacity', 100); // hardcoded voor nu
$xml->addChild('EventType', 'default');

$reg = $xml->addChild('RegisteredUsers');
foreach ($attendee_uuids as $uuid) {
    $userNode = $reg->addChild('User');
    $userNode->addChild('UUID', $uuid);
}

// 4. Versturen naar RabbitMQ
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
    $calendarId = 'frontend@youmnimalha.be';
    $events = fetch_all_events_from_calendar($calendarId);

    $formatted = [];
    foreach ($events as $event) {
        $formatted[] = [
            'id' => $event->getId(),
            'summary' => $event->getSummary(),
            'description' => $event->getDescription(),
            'start' => $event->getStart()->getDateTime() ?? $event->getStart()->getDate(),
            'end' => $event->getEnd()->getDateTime() ?? $event->getEnd()->getDate()
        ];
    }

    wp_send_json($formatted);
}

// Shortcode: toont events met inschrijfknop
add_shortcode('expo_events', 'expo_render_events');
    }
});


