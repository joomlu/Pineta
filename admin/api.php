<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/pineta_server.php';

session_start();

$action = (string) ($_GET['action'] ?? 'session');

try {
    if ($action === 'login') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            pineta_json_response(['ok' => false, 'error' => 'Metodo non consentito.'], 405);
        }

        $payload = pineta_read_json_input();
        $username = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if (!pineta_admin_credentials_are_valid($username, $password)) {
            pineta_json_response(['ok' => false, 'error' => 'Credenziali non valide.'], 401);
        }

        $_SESSION['pineta_admin_ok'] = true;
        pineta_json_response(['ok' => true]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        pineta_json_response(['ok' => true]);
    }

    if ($action === 'session') {
        if (empty($_SESSION['pineta_admin_ok'])) {
            pineta_json_response(['ok' => true, 'authenticated' => false]);
        }

        pineta_json_response([
            'ok' => true,
            'authenticated' => true,
            'items' => pineta_read_requests(),
        ]);
    }

    pineta_require_admin_session();

    if ($action === 'list') {
        pineta_json_response([
            'ok' => true,
            'items' => pineta_read_requests(),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pineta_json_response(['ok' => false, 'error' => 'Metodo non consentito.'], 405);
    }

    $payload = pineta_read_json_input();
    $items = pineta_read_requests();

    if ($action === 'update') {
        $id = trim((string) ($payload['id'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        $allowed = ['nuova', 'vista', 'risposta'];

        if ($id === '' || !in_array($status, $allowed, true)) {
            pineta_json_response(['ok' => false, 'error' => 'Dati non validi.'], 422);
        }

        $updated = array_map(static function (array $item) use ($id, $status): array {
            if (($item['id'] ?? '') !== $id) {
                return $item;
            }

            $item['status'] = $status;
            if ($status === 'vista') {
                $item['viewedAt'] = gmdate('c');
            }
            if ($status === 'risposta') {
                $item['respondedAt'] = gmdate('c');
            }
            return $item;
        }, $items);

        pineta_write_requests($updated);
        pineta_json_response(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = trim((string) ($payload['id'] ?? ''));
        $updated = array_values(array_filter($items, static fn(array $item): bool => ($item['id'] ?? '') !== $id));
        pineta_write_requests($updated);
        pineta_json_response(['ok' => true]);
    }

    if ($action === 'clear') {
        pineta_write_requests([]);
        pineta_json_response(['ok' => true]);
    }

    pineta_json_response(['ok' => false, 'error' => 'Azione non supportata.'], 404);
} catch (Throwable $error) {
    pineta_json_response([
        'ok' => false,
        'error' => 'Errore interno del CRM server-side.',
    ], 500);
}
