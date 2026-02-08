<?php
// api/subscription_config.php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/bootstrap.php';

$price = (float) env('SUBSCRIPTION_PRICE', '21.90');
$currency = env('SUBSCRIPTION_CURRENCY', 'BRL') ?: 'BRL';

echo json_encode([
    'trial_days' => TRIAL_DAYS,
    'price' => $price,
    'currency' => $currency,
], JSON_UNESCAPED_UNICODE);
?>
