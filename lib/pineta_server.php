<?php
declare(strict_types=1);

function pineta_config(): array
{
    static $config;

    if ($config === null) {
        /** @var array $loaded */
        $loaded = require dirname(__DIR__) . '/app_config.php';
        $config = $loaded;
    }

    return $config;
}

function pineta_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pineta_read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function pineta_storage_path(): string
{
    return pineta_config()['crm_storage'];
}

function pineta_ensure_storage(): void
{
    $path = pineta_storage_path();
    $dir = dirname($path);

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossibile creare la cartella del CRM.');
    }

    if (!file_exists($path) && file_put_contents($path, "[]\n", LOCK_EX) === false) {
        throw new RuntimeException('Impossibile inizializzare il file del CRM.');
    }
}

function pineta_read_requests(): array
{
    pineta_ensure_storage();
    $raw = file_get_contents(pineta_storage_path());
    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function pineta_write_requests(array $items): void
{
    pineta_ensure_storage();
    $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false || file_put_contents(pineta_storage_path(), $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile salvare le richieste del CRM.');
    }
}

function pineta_string(array $input, string $key): string
{
    return trim((string) ($input[$key] ?? ''));
}

function pineta_build_request(array $input): array
{
    $request = [
        'id' => date('YmdHis') . '-' . bin2hex(random_bytes(4)),
        'createdAt' => gmdate('c'),
        'status' => 'nuova',
        'channel' => pineta_string($input, 'channel') ?: 'email',
        'tipo' => pineta_string($input, 'tipo'),
        'nome' => pineta_string($input, 'nome'),
        'telefono' => pineta_string($input, 'telefono'),
        'email' => pineta_string($input, 'email'),
        'arrivo' => pineta_string($input, 'arrivo'),
        'partenza' => pineta_string($input, 'partenza'),
        'trattamento' => pineta_string($input, 'trattamento'),
        'camere' => pineta_string($input, 'camere'),
        'sistemazione' => pineta_string($input, 'sistemazione'),
        'contattoPreferito' => pineta_string($input, 'contattoPreferito'),
        'orarioContatto' => pineta_string($input, 'orarioContatto'),
        'messaggio' => pineta_string($input, 'messaggio'),
        'composizioneCamere' => pineta_string($input, 'composizioneCamere'),
        'rawMessage' => pineta_string($input, 'rawMessage'),
    ];

    if ($request['nome'] === '' || $request['telefono'] === '' || $request['email'] === '') {
        throw new InvalidArgumentException('Compila nome, telefono ed email prima di inviare la richiesta.');
    }

    if (!filter_var($request['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('L’indirizzo email inserito non è valido.');
    }

    return $request;
}

function pineta_build_subject(array $request): string
{
    $tipo = $request['tipo'] !== '' ? $request['tipo'] : 'Richiesta soggiorno';
    $nome = $request['nome'] !== '' ? $request['nome'] : 'Contatto sito';
    return $tipo . ' - ' . $nome . ' - Hotel Pineta';
}

function pineta_build_body(array $request): string
{
    $lines = [
        'Nuova richiesta sito Hotel Pineta',
        '',
        'Tipo: ' . ($request['tipo'] ?: '-'),
        'Canale: ' . ($request['channel'] ?: '-'),
        'Nome: ' . ($request['nome'] ?: '-'),
        'Telefono: ' . ($request['telefono'] ?: '-'),
        'Email: ' . ($request['email'] ?: '-'),
        'Arrivo: ' . ($request['arrivo'] ?: '-'),
        'Partenza: ' . ($request['partenza'] ?: '-'),
        'Trattamento: ' . ($request['trattamento'] ?: '-'),
        'Camere: ' . ($request['camere'] ?: '-'),
        'Sistemazione: ' . ($request['sistemazione'] ?: '-'),
        'Contatto preferito: ' . ($request['contattoPreferito'] ?: '-'),
        'Orario contatto: ' . ($request['orarioContatto'] ?: '-'),
        '',
        'Composizione camere:',
        $request['composizioneCamere'] !== '' ? $request['composizioneCamere'] : '-',
        '',
        'Messaggio:',
        $request['messaggio'] !== '' ? $request['messaggio'] : '-',
        '',
        'Messaggio completo form:',
        $request['rawMessage'] !== '' ? $request['rawMessage'] : '-',
        '',
        'ID CRM: ' . $request['id'],
        'Creato il: ' . $request['createdAt'],
    ];

    return implode("\r\n", $lines);
}

function pineta_send_smtp_mail(string $subject, string $body, array $replyTo = []): void
{
    $config = pineta_config();
    $smtp = $config['smtp'];
    $recipients = $config['lead_recipients'];
    $timeout = max(5, (int) ($smtp['timeout'] ?? 15));
    $attempts = pineta_smtp_attempts($smtp);

    if (function_exists('set_time_limit')) {
        @set_time_limit(($timeout * count($attempts)) + 5);
    }

    $errors = [];

    foreach ($attempts as $attempt) {
        $socket = null;
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message);
        });

        try {
            $socket = pineta_smtp_connect($attempt, $timeout);
            pineta_smtp_expect($socket, [220]);
            pineta_smtp_handshake($socket, $attempt);
            pineta_smtp_command($socket, 'AUTH LOGIN', [334]);
            pineta_smtp_command($socket, base64_encode($smtp['username']), [334]);
            pineta_smtp_command($socket, base64_encode($smtp['password']), [235]);
            pineta_smtp_command($socket, 'MAIL FROM:<' . $smtp['from_email'] . '>', [250]);

            foreach ($recipients as $recipient) {
                pineta_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            pineta_smtp_command($socket, 'DATA', [354]);

            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . pineta_format_from($smtp['from_name'], $smtp['from_email']),
                'To: ' . implode(', ', $recipients),
                'Subject: ' . pineta_encode_header($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'Message-ID: <' . uniqid('pineta-', true) . '@' . $attempt['host'] . '>',
                'X-Mailer: Hotel Pineta Lead Handler',
            ];

            if (!empty($replyTo['email']) && filter_var($replyTo['email'], FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'Reply-To: ' . pineta_format_from($replyTo['name'] ?? '', $replyTo['email']);
            }

            $message = implode("\r\n", $headers) . "\r\n\r\n" . pineta_dot_stuff($body) . "\r\n.";
            pineta_smtp_command($socket, $message, [250]);
            pineta_smtp_command($socket, 'QUIT', [221]);
            return;
        } catch (Throwable $error) {
            $errors[] = sprintf(
                '%s:%d/%s - %s',
                $attempt['host'],
                (int) $attempt['port'],
                $attempt['security'],
                $error->getMessage()
            );
        } finally {
            restore_error_handler();

            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    throw new RuntimeException(implode(' | ', $errors));
}

function pineta_smtp_attempts(array $smtp): array
{
    $attempts = $smtp['attempts'] ?? [];
    if (is_array($attempts) && $attempts !== []) {
        return array_values(array_filter($attempts, static function ($attempt): bool {
            return is_array($attempt)
                && !empty($attempt['host'])
                && !empty($attempt['port'])
                && !empty($attempt['security']);
        }));
    }

    return [[
        'host' => (string) ($smtp['host'] ?? ''),
        'port' => (int) ($smtp['port'] ?? 465),
        'security' => (string) ($smtp['security'] ?? 'ssl'),
    ]];
}

function pineta_smtp_connect(array $attempt, int $timeout)
{
    $security = strtolower((string) ($attempt['security'] ?? 'ssl'));
    $host = (string) ($attempt['host'] ?? '');
    $port = (int) ($attempt['port'] ?? 0);
    $transport = $security === 'ssl' ? 'ssl://' : 'tcp://';

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    $socket = stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        (float) $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        throw new RuntimeException('Connessione SMTP non riuscita: ' . $errstr);
    }

    stream_set_timeout($socket, $timeout);
    return $socket;
}

function pineta_smtp_handshake($socket, array $attempt): void
{
    $host = (string) ($attempt['host'] ?? 'localhost');
    $security = strtolower((string) ($attempt['security'] ?? 'ssl'));

    pineta_smtp_command($socket, 'EHLO ' . $host, [250]);

    if ($security !== 'tls') {
        return;
    }

    pineta_smtp_command($socket, 'STARTTLS', [220]);
    $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($cryptoEnabled !== true) {
        throw new RuntimeException('Impossibile attivare STARTTLS.');
    }

    pineta_smtp_command($socket, 'EHLO ' . $host, [250]);
}

function pineta_smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return pineta_smtp_expect($socket, $expectedCodes);
}

function pineta_smtp_expect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Errore SMTP (' . $code . '): ' . trim($response));
    }

    return $response;
}

function pineta_encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function pineta_format_from(string $name, string $email): string
{
    $trimmedName = trim($name);
    if ($trimmedName === '') {
        return '<' . $email . '>';
    }

    return pineta_encode_header($trimmedName) . ' <' . $email . '>';
}

function pineta_dot_stuff(string $body): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $body);
    $normalized = preg_replace('/^\./m', '..', $normalized ?? '');
    return str_replace("\n", "\r\n", $normalized ?? '');
}

function pineta_admin_credentials_are_valid(string $username, string $password): bool
{
    $config = pineta_config()['admin'];
    return hash_equals($config['username'], $username) && hash_equals($config['password'], $password);
}

function pineta_require_admin_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['pineta_admin_ok'])) {
        pineta_json_response([
            'ok' => false,
            'error' => 'Sessione admin non autorizzata.',
        ], 401);
    }
}
