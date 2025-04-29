<?php
/*
Plugin Name: Google Calendar Sync
Description: Synchroniseert events van Google Calendar naar een shortcode.
Version: 1.0
Author: Jouw naam
*/

add_shortcode('google_calendar', 'gcal_render_calendar');

function gcal_render_calendar() {
    ob_start();
    ?>
    <div id="calendar">Laden...</div>
    <script src="https://apis.google.com/js/api.js"></script>
    <script>
      function start() {
        gapi.client.init({
          apiKey: 'AIzaSyDYIzq88EY_Ne8uz_WI31LO5jK6i_fkXjA',
          clientId: '460032815861-lj95nsl6a9h1rgh64lilg8sg5j5boc65.apps.googleusercontent.com',
          discoveryDocs: ["https://www.googleapis.com/discovery/v1/apis/calendar/v3/rest"],
          scope: "https://www.googleapis.com/auth/calendar.readonly"
        }).then(function () {
          return gapi.client.calendar.events.list({
            'calendarId': 'jouw_calendar_id@group.calendar.google.com',
            'timeMin': (new Date()).toISOString(),
            'showDeleted': false,
            'singleEvents': true,
            'maxResults': 10,
            'orderBy': 'startTime'
          });
        }).then(function(response) {
          var events = response.result.items;
          let output = "<ul>";
          if (events.length > 0) {
            events.forEach(event => {
              const start = event.start.dateTime || event.start.date;
              output += `<li>${start} - ${event.summary}</li>`;
            });
          } else {
            output += "<li>Geen evenementen gevonden.</li>";
          }
          output += "</ul>";
          document.getElementById("calendar").innerHTML = output;
        });
      }

      gapi.load('client', start);
    </script>
    <?php
    return ob_get_clean();
}
?>
