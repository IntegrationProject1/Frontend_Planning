require_once __DIR__ . '/../../vendor/autoload.php'; // Pad aanpassen indien nodig

function get_google_calendar_service() {
    $client = new \Google_Client();
    $client->setAuthConfig(__DIR__ . '/../credentials/calendar-service-account.json');


    $client->addScope('https://www.googleapis.com/auth/calendar');


    $client->setSubject('frontend@youmnimalha.be');

    return new Google_Service_Calendar($client);
}
