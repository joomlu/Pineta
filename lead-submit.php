<?php
declare(strict_types=1);

require __DIR__ . '/lib/pineta_server.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pineta_json_response([
        'ok' => false,
        'error' => 'Metodo non consentito.',
    ], 405);
}

try {
    $payload = pineta_read_json_input();
    $request = pineta_build_request($payload);

    try {
        pineta_send_smtp_mail(
            pineta_build_subject($request),
            pineta_build_body($request),
            ['name' => $request['nome'], 'email' => $request['email']]
        );
        $request['deliveredAt'] = gmdate('c');
    } catch (Throwable $mailError) {
        $request['deliveryError'] = $mailError->getMessage();
        $requests = pineta_read_requests();
        array_unshift($requests, $request);
        pineta_write_requests($requests);

        pineta_json_response([
            'ok' => false,
            'error' => 'La richiesta e stata salvata nel CRM, ma l’invio email non e riuscito. Controlla la configurazione SMTP del server.',
        ], 500);
    }

    $requests = pineta_read_requests();
    array_unshift($requests, $request);
    pineta_write_requests($requests);

    pineta_json_response([
        'ok' => true,
        'message' => 'Richiesta inviata correttamente ai due indirizzi email e salvata nel CRM.',
        'id' => $request['id'],
    ]);
} catch (InvalidArgumentException $validationError) {
    pineta_json_response([
        'ok' => false,
        'error' => $validationError->getMessage(),
    ], 422);
} catch (Throwable $error) {
    pineta_json_response([
        'ok' => false,
        'error' => 'Errore interno durante la gestione della richiesta.',
    ], 500);
}
