<?php
require_once __DIR__ . '/includes/smtp_mailer.php';
require_once __DIR__ . '/includes/stripe.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit;
}
$config = require $configPath;
$stripeConfig = $config['stripe'] ?? [];
$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if (!verifyStripeWebhook($payload, $signature, (string)($stripeConfig['webhook_secret'] ?? ''))) {
    http_response_code(400);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type']) || empty($event['data']['object'])) {
    http_response_code(400);
    exit;
}
try {
    $pdo = new PDO(
        'mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $session = $event['data']['object'];
    if ($event['type'] === 'checkout.session.completed'
        || $event['type'] === 'checkout.session.async_payment_succeeded') {
        if (($session['payment_status'] ?? '') === 'paid' && !empty($session['id'])) {
            completeStripeOrder($pdo, $config, (string)$session['id'], $session);
        }
    }
    elseif ($event['type'] === 'checkout.session.expired' && !empty($session['id'])) {
        cancelExpiredStripeOrder($pdo, (string)$session['id']);
    }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
}
catch (Throwable $error) {
    error_log('[GadgetsY+] Error al procesar webhook de Stripe (' . get_class($error) . ').');
    http_response_code(500);
    echo 'retry';
}
