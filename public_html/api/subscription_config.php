<?php
// api/subscription_config.php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/bootstrap.php';

$price = (float) env('SUBSCRIPTION_PRICE', '21.90');
$currency = env('SUBSCRIPTION_CURRENCY', 'BRL') ?: 'BRL';
$enableAltPayments = env('ENABLE_ALT_PAYMENTS', '0') === '1';
$altPaymentsDefault = env('ALT_PAYMENTS_DEFAULT', 'all') ?: 'all';
$altPaymentsDefault = strtolower($altPaymentsDefault);
if (!in_array($altPaymentsDefault, ['all', 'pix', 'boleto'], true)) {
    $altPaymentsDefault = 'all';
}

echo json_encode([
    'trial_days' => TRIAL_DAYS,
    'price' => $price,
    'currency' => $currency,
    'enable_alt_payments' => $enableAltPayments,
    'alt_payments_default' => $altPaymentsDefault,
], JSON_UNESCAPED_UNICODE);
?>
