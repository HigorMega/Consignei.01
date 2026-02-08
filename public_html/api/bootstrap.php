<?php
// api/bootstrap.php
// Carrega variáveis de ambiente a partir de um arquivo .env (se existir)

function loadEnvFile(string $path): void {
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, null);
        if ($name === null || $value === null) {
            continue;
        }

        $name = trim($name);
        $value = trim($value);
        $value = trim($value, "\"'");

        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

loadEnvFile(__DIR__ . '/../.env');
loadEnvFile(__DIR__ . '/../.env.example');

function get_trial_days(): int {
    $rawValue = env('TRIAL_DAYS', '0');
    $trialDays = is_numeric($rawValue) ? (int) $rawValue : 0;
    if ($trialDays < 0) {
        $trialDays = 0;
    }
    if ($trialDays > 60) {
        $trialDays = 60;
    }
    return $trialDays;
}

if (!defined('TRIAL_DAYS')) {
    define('TRIAL_DAYS', get_trial_days());
}
