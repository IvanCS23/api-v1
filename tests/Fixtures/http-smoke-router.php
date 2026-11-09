<?php

/**
 * Fase 6.33 (3): servidor local (`php -S`) para la prueba de humo del
 * transporte HTTP real (Guzzle). Nunca sale a la red.
 *
 * - /echo      devuelve el host y si llegó el encabezado Authorization.
 * - /redirect  redirige (302) a ?to=.
 * - /slow      tarda ?s= segundos en responder.
 * - /range/*   responde como api.pwnedpasswords.com (sin coincidencias).
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);

header('Content-Type: application/json');

if ($path === '/echo') {
    echo json_encode([
        'host' => $headers['host'] ?? null,
        'authorization' => isset($headers['authorization']),
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    ]);

    return true;
}

if ($path === '/redirect') {
    header('Location: '.($_GET['to'] ?? '/echo'), true, 302);

    return true;
}

if ($path === '/slow') {
    sleep((int) ($_GET['s'] ?? 5));
    echo '{}';

    return true;
}

if (str_starts_with($path, '/range/')) {
    header('Content-Type: text/plain');
    echo "0000000000000000000000000000000000A:1\r\n";

    return true;
}

http_response_code(404);
echo '{}';

return true;
