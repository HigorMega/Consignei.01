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
