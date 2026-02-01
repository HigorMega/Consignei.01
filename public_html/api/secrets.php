<?php
// api/secrets.php
require __DIR__ . '/bootstrap.php';

// Token da API OCR (Use variáveis de ambiente no .env)
define('OCR_TOKEN', env('OCR_TOKEN'));
define('OCR_URL', env('OCR_URL'));

// Segurança de Domínio (Substitua pelo seu domínio real quando for para produção)
// Exemplo: define('ALLOWED_ORIGIN', 'https://consigneiapp.com.br');
define('ALLOWED_ORIGIN', env('ALLOWED_ORIGIN', '*'));
?>
