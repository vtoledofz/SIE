<?php
function stripeApiRequest(array $stripe, string $method, string $path, array $data = []): array {
    $secretKey = trim((string)($stripe['secret_key'] ?? ''));
    $timeout = max(5, min(45, (int)($stripe['timeout'] ?? 25)));
    if ($secretKey === '' || !preg_match('/^sk_(test|live)_/', $secretKey)) {
        throw new RuntimeException('Falta una clave secreta Stripe válida.');
    }
    if (str_starts_with($secretKey, 'sk_live_') && empty($stripe['allow_live_payments'])) {
        throw new RuntimeException('Los pagos reales están desactivados en config.php.');
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ];
    $body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (strtoupper($method) !== 'GET') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($curl);
        curl_close($curl);
        if ($responseBody === false || $curlError !== 0) {
            throw new RuntimeException('No se pudo conectar con Stripe.');
        }
    }
    else {
        if (ini_get('allow_url_fopen') !== '1') {
            throw new RuntimeException('El hosting no permite peticiones HTTPS salientes.');
        }
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => strtoupper($method) === 'GET' ? '' : $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $responseBody = file_get_contents($url, false, $context);
        $httpCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
        if ($responseBody === false) {
            throw new RuntimeException('No se pudo conectar con Stripe.');
        }
    }

    $decoded = json_decode((string)$responseBody, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
        throw new RuntimeException('Stripe rechazó la petición.');
    }
    return $decoded;
}

function createStripeCheckout(array $config, array $order, array $items): array {
    $stripe = $config['stripe'] ?? [];
    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('Falta una URL HTTPS válida en config.php.');
    }
    $currency = strtolower((string)($stripe['currency'] ?? 'eur'));
    if (!preg_match('/^[a-z]{3}$/', $currency)) {
        throw new RuntimeException('Moneda de Stripe no válida.');
    }

    $descriptionParts = [];
    foreach ($items as $item) {
        $label = $item['name'];
        if ($item['variant_label'] !== '') {
            $label .= ' (' . $item['variant_label'] . ')';
        }
        $descriptionParts[] = $label . ' × ' . (int)$item['qty'];
    }
    $description = implode('; ', $descriptionParts);
    if (strlen($description) > 480) {
        $description = substr($description, 0, 477) . '...';
    }
    $metadata = [
        'order_id' => (string)$order['id'],
        'order_code' => (string)$order['order_code'],
    ];
    $payload = [
        'mode' => 'payment',
        'locale' => 'es',
        'expires_at' => time() + 3600,
        'payment_method_types' => ['card'],
        'success_url' => $baseUrl . '/?page=success&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $baseUrl . '/?page=payment&order=' . rawurlencode((string)$order['order_code']),
        'customer_email' => $order['email'],
        'client_reference_id' => $order['order_code'],
        'metadata' => $metadata,
        'payment_intent_data' => ['metadata' => $metadata],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => (int)round((float)$order['total'] * 100),
                'product_data' => [
                    'name' => 'Pedido GadgetsY+ ' . $order['order_code'],
                    'description' => $description . ' · Incluye IVA y envío',
                ],
            ],
        ]],
    ];
    $session = stripeApiRequest($stripe, 'POST', 'checkout/sessions', $payload);
    $checkoutUrl = (string)($session['url'] ?? '');
    $checkoutHost = parse_url($checkoutUrl, PHP_URL_HOST);
    if (empty($session['id']) || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
        || $checkoutHost !== 'checkout.stripe.com') {
        throw new RuntimeException('Stripe devolvió una sesión de pago no válida.');
    }
    return $session;
}

function verifyStripeWebhook(string $payload, string $signatureHeader, string $webhookSecret): bool {
    if ($payload === '' || $signatureHeader === '' || $webhookSecret === '') {
        return false;
    }
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) continue;
        if ($pair[0] === 't') $timestamp = $pair[1];
        if ($pair[0] === 'v1') $signatures[] = $pair[1];
    }
    if (!ctype_digit((string)$timestamp) || abs(time() - (int)$timestamp) > 300 || !$signatures) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $webhookSecret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) return true;
    }
    return false;
}

function sendStripeOrderConfirmation(PDO $db, array $config, int $orderId): bool {
    $formatMoney = static fn($amount) => number_format((float)$amount, 2, ',', '.') . ' €';
    $query = $db->prepare('SELECT o.order_code,o.subtotal,o.shipping,o.tax,o.discount,o.total,c.email FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
    $query->execute([$orderId]);
    $order = $query->fetch();
    if (!$order) return false;

    $query = $db->prepare('SELECT product_name,variant_label,quantity,unit_price FROM order_items WHERE order_id=? ORDER BY id');
    $query->execute([$orderId]);
    $receiptLines = [];
    foreach ($query->fetchAll() as $item) {
        $label = $item['product_name'];
        if ($item['variant_label'] !== '') $label .= ' — ' . $item['variant_label'];
        $receiptLines[] = $label . ' × ' . $item['quantity'] . ' · ' . $formatMoney((float)$item['unit_price'] * (int)$item['quantity']);
    }
    $receipt = "Gracias por tu pedido en GadgetsY+.\n\n"
        . 'Pedido: ' . $order['order_code'] . "\nPago: recibido mediante Stripe\n\n"
        . "Productos:\n" . implode("\n", $receiptLines) . "\n\n"
        . 'Subtotal (IVA incluido): ' . $formatMoney($order['subtotal']) . "\n"
        . 'Descuento: ' . $formatMoney($order['discount']) . "\n"
        . 'Envío: ' . $formatMoney($order['shipping']) . "\n"
        . 'IVA incluido: ' . $formatMoney($order['tax']) . "\n"
        . 'Total: ' . $formatMoney($order['total']) . "\n\nGadgetsY+";
    $mail = $config['mail'] ?? [];
    return sendStoreEmail(
        $mail,
        'orders',
        $order['email'],
        'Confirmación de pedido ' . $order['order_code'],
        $receipt,
        null,
        $mail['orders']['address'] ?? null
    );
}

function completeStripeOrder(PDO $db, array $config, string $sessionId, array $session): ?array {
    if (($session['payment_status'] ?? '') !== 'paid'
        || ($session['id'] ?? '') !== $sessionId
        || empty($session['metadata']['order_id'])) {
        return null;
    }
    $orderId = (int)$session['metadata']['order_id'];
    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT o.id,o.status,o.order_code,p.id AS payment_id FROM orders o JOIN payments p ON p.order_id=o.id WHERE o.id=? AND p.method='stripe' AND p.reference_code=? FOR UPDATE");
        $query->execute([$orderId, $sessionId]);
        $row = $query->fetch();
        if (!$row) {
            $db->rollBack();
            return null;
        }
        if (($session['metadata']['order_code'] ?? '') !== $row['order_code']) {
            $db->rollBack();
            return null;
        }
        $shouldSendConfirmation = $row['status'] === 'payment_pending';
        if ($shouldSendConfirmation) {
            $db->prepare("UPDATE orders SET status='paid' WHERE id=? AND status='payment_pending'")->execute([$orderId]);
            $db->prepare("UPDATE payments SET status='succeeded' WHERE id=?")->execute([$row['payment_id']]);
            $event = $db->prepare('INSERT INTO events(order_id,event_name,payload) VALUES(?,?,?)');
            $event->execute([$orderId, 'payment.stripe_succeeded', json_encode([
                'order_id' => $orderId,
                'order_code' => $row['order_code'],
                'session_id' => $sessionId,
                'payment_intent' => $session['payment_intent'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
        elseif ($row['status'] !== 'paid') {
            $db->rollBack();
            return null;
        }
        $db->commit();
    }
    catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }

    if ($shouldSendConfirmation) {
        $sent = sendStripeOrderConfirmation($db, $config, $orderId);
        if (!empty($config['mail']['enabled']) && !$sent) {
            error_log('[GadgetsY+] Stripe pagado; falló el correo de confirmación. Pedido: ' . $orderId);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['email_notice'] = 'El pago se ha confirmado, pero no se pudo enviar el correo. Guarda el identificador del pedido.';
            }
        }
    }
    return ['id' => $orderId, 'code' => $row['order_code']];
}

function cancelExpiredStripeOrder(PDO $db, string $sessionId): bool {
    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT o.id,o.status,p.id AS payment_id FROM orders o JOIN payments p ON p.order_id=o.id WHERE p.method='stripe' AND p.reference_code=? FOR UPDATE");
        $query->execute([$sessionId]);
        $row = $query->fetch();
        if (!$row || $row['status'] !== 'payment_pending') {
            $db->commit();
            return false;
        }
        $items = $db->prepare('SELECT product_id,variant_id,quantity FROM order_items WHERE order_id=?');
        $items->execute([$row['id']]);
        foreach ($items->fetchAll() as $item) {
            $db->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$item['quantity'], $item['product_id']]);
            if ($item['variant_id']) {
                $db->prepare('UPDATE product_variants SET stock=stock+? WHERE id=?')->execute([$item['quantity'], $item['variant_id']]);
            }
        }
        $db->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$row['id']]);
        $db->prepare("UPDATE payments SET status='expired' WHERE id=?")->execute([$row['payment_id']]);
        $event = $db->prepare('INSERT INTO events(order_id,event_name,payload) VALUES(?,?,?)');
        $event->execute([(int)$row['id'], 'payment.stripe_expired', json_encode([
            'order_id' => (int)$row['id'],
            'session_id' => $sessionId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $db->commit();
        return true;
    }
    catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function cancelUnstartedStripeOrder(PDO $db, int $orderId): bool {
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT id,status FROM orders WHERE id=? FOR UPDATE');
        $query->execute([$orderId]);
        $order = $query->fetch();
        if (!$order || $order['status'] !== 'payment_pending') {
            $db->commit();
            return false;
        }
        $items = $db->prepare('SELECT product_id,variant_id,quantity FROM order_items WHERE order_id=?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll() as $item) {
            $db->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$item['quantity'], $item['product_id']]);
            if ($item['variant_id']) {
                $db->prepare('UPDATE product_variants SET stock=stock+? WHERE id=?')->execute([$item['quantity'], $item['variant_id']]);
            }
        }
        $db->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$orderId]);
        $db->prepare("UPDATE payments SET status='failed' WHERE order_id=? AND method='stripe' AND status='pending'")->execute([$orderId]);
        $event = $db->prepare('INSERT INTO events(order_id,event_name,payload) VALUES(?,?,?)');
        $event->execute([$orderId, 'payment.stripe_start_failed', json_encode(['order_id' => $orderId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $db->commit();
        return true;
    }
    catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
