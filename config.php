<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Phnom_Penh');

/*
  1) Import install.sql dulu.
  2) Ubah DB_USER dan DB_PASS sesuai MySQL/XAMPP kamu.
  3) Supaya upload masuk ke akun/profile Imgur kamu, isi IMGUR_ACCESS_TOKEN dari OAuth Imgur akun yonnn4a.
     Kalau hanya isi CLIENT_ID tanpa ACCESS_TOKEN, upload bisa anonymous dan tidak otomatis masuk profile.
*/

const DB_HOST = 'localhost';
const DB_NAME = 'db_gege';
const DB_USER = 'root';
const DB_PASS = '';

const IMGUR_USERNAME = 'ynnn4a';
const IMGUR_CLIENT_ID = 'ISI_CLIENT_ID_IMGUR_KAMU';
const IMGUR_ACCESS_TOKEN = 'ISI_ACCESS_TOKEN_OAUTH_IMGUR_KAMU';
const IMGUR_ALBUM_ID = ''; // optional: isi album id kalau mau semua gambar masuk album tertentu

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function imgur_auth_header(): string
{
    $token = trim(IMGUR_ACCESS_TOKEN);
    if ($token !== '' && !str_starts_with($token, 'ISI_')) {
        return 'Authorization: Bearer ' . $token;
    }

    $clientId = trim(IMGUR_CLIENT_ID);
    if ($clientId !== '' && !str_starts_with($clientId, 'ISI_')) {
        return 'Authorization: Client-ID ' . $clientId;
    }

    throw new RuntimeException('Isi IMGUR_ACCESS_TOKEN untuk upload ke profile Imgur, atau minimal IMGUR_CLIENT_ID untuk upload anonymous.');
}
