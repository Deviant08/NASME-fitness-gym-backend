<?php
/* Health check so Railway / and browsers get a real response */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
echo json_encode([
    'ok'      => true,
    'service' => 'NASME GYM API',
    'health'  => '/api/auth.php?action=me',
], JSON_PRETTY_PRINT);
