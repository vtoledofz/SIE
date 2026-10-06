<?php
function smtpReadResponse($socket): array {
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('Conexión SMTP cerrada inesperadamente.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    if (!preg_match('/^(\d{3})/', $response, $matches)) {
        throw new RuntimeException('Respuesta SMTP no válida.');
    }
    return [(int)$matches[1], $response];
}

function smtpCommand($socket, string $command, array $expectedCodes): string {
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('No se pudo enviar un comando SMTP.');
    }
    [$code, $response] = smtpReadResponse($socket);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('El servidor SMTP rechazó una operación.');
    }
    return $response;
}

function sendStoreEmail(
    array $mailConfig,
    string $mailbox,
    string $recipient,
    string $subject,
    string $body,
    ?string $replyTo = null,
    ?string $copyTo = null
): bool {
    if (empty($mailConfig['enabled'])) {
        return false;
    }

    $account = $mailConfig[$mailbox] ?? [];
    $host = trim((string)($mailConfig['host'] ?? ''));
    $port = (int)($mailConfig['port'] ?? 587);
    $from = trim((string)($account['address'] ?? ''));
    $password = (string)($account['password'] ?? '');
    $timeout = max(5, min(30, (int)($mailConfig['timeout'] ?? 15)));
    $security = strtolower((string)($mailConfig['security'] ?? 'starttls'));

    foreach ([$recipient, $from] as $address) {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            error_log('[GadgetsY+] SMTP desactivado: dirección de correo no válida en config.php.');
            return false;
        }
    }
    if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = null;
    }
    if ($copyTo !== null && !filter_var($copyTo, FILTER_VALIDATE_EMAIL)) {
        $copyTo = null;
    }
    if ($host === '' || $password === '' || $port < 1 || $port > 65535 || $security !== 'starttls') {
        error_log('[GadgetsY+] SMTP no está configurado correctamente en config.php.');
        return false;
    }
    if (!extension_loaded('openssl')) {
        error_log('[GadgetsY+] No se pudo enviar correo: falta la extensión OpenSSL de PHP.');
        return false;
    }

    $recipients = array_values(array_unique(array_filter([$recipient, $copyTo])));
    $socket = null;
    try {
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $socket = stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            throw new RuntimeException('No se pudo conectar al servidor SMTP.');
        }
        stream_set_timeout($socket, $timeout);
        [$code] = smtpReadResponse($socket);
        if ($code !== 220) {
            throw new RuntimeException('Saludo SMTP no válido.');
        }

        smtpCommand($socket, 'EHLO ' . $host, [250]);
        smtpCommand($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('No se pudo establecer TLS con el servidor SMTP.');
        }
        smtpCommand($socket, 'EHLO ' . $host, [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($from), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
        foreach ($recipients as $address) {
            smtpCommand($socket, 'RCPT TO:<' . $address . '>', [250, 251]);
        }
        smtpCommand($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'From: =?UTF-8?B?' . base64_encode('GadgetsY+') . '?= <' . $from . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($copyTo !== null && $copyTo !== $recipient) {
            $headers[] = 'Cc: <' . $copyTo . '>';
        }
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n"), "\r\n");
        if (fwrite($socket, $message . "\r\n.\r\n") === false) {
            throw new RuntimeException('No se pudo transmitir el correo.');
        }
        [$code] = smtpReadResponse($socket);
        if ($code !== 250) {
            throw new RuntimeException('El servidor SMTP no aceptó el correo.');
        }
        smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    }
    catch (Throwable $error) {
        if (is_resource($socket)) {
            fclose($socket);
        }
        // No registrar mensajes SMTP ni credenciales; solo el tipo de error.
        error_log('[GadgetsY+] Falló el envío SMTP (' . $mailbox . '): ' . get_class($error));
        return false;
    }
}
