<?php

return [
    // Set this only to the ISPConfig ID of the API's own web server. Zero uses workers only.
    'local_server_id' => (int) env('WEB_LOG_SERVER_ID', 0),
    'root' => '/var/log/ispconfig/httpd',
];
