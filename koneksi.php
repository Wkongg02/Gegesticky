<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| koneksi.php - Database Connection untuk db_gege
|--------------------------------------------------------------------------
| Simpan file ini di folder project yang sama dengan index.php.
|
| Cara panggil di index.php:
| require_once __DIR__ . '/koneksi.php';
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Phnom_Penh');

/* =========================
   SETTING DATABASE
========================= */
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'db_gege');
}

if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}

if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}

/* =========================
   SETTING IMGUR
   Isi kalau mau upload gambar ke akun Imgur
========================= */
if (!defined('IMGUR_USERNAME')) {
    define('IMGUR_USERNAME', 'ynnn4a');
}

if (!defined('IMGUR_CLIENT_ID')) {
    define('IMGUR_CLIENT_ID', 'ISI_CLIENT_ID_IMGUR_KAMU');
}

if (!defined('IMGUR_ACCESS_TOKEN')) {
    define('IMGUR_ACCESS_TOKEN', 'ISI_ACCESS_TOKEN_OAUTH_IMGUR_KAMU');
}

if (!defined('IMGUR_ALBUM_ID')) {
    define('IMGUR_ALBUM_ID', '');
}

/* =========================
   KONEKSI MYSQLI
   Pakai variable ini kalau kode kamu memakai mysqli:
   $koneksi
========================= */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $koneksi = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $koneksi->set_charset('utf8mb4');

    $koneksi->query(
        "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
         CHARACTER SET utf8mb4
         COLLATE utf8mb4_unicode_ci"
    );

    $koneksi->select_db(DB_NAME);
} catch (Throwable $e) {
    die('Koneksi MySQLi gagal: ' . $e->getMessage());
}

/* =========================
   KONEKSI PDO
   Pakai function ini kalau kode kamu memakai PDO:
   db()
========================= */
if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            try {
                $dsnServer = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
                $pdoServer = new PDO($dsnServer, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);

                $pdoServer->exec(
                    "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                     CHARACTER SET utf8mb4
                     COLLATE utf8mb4_unicode_ci"
                );

                $dsnDb = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                $pdo = new PDO($dsnDb, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                install_db_gege_tables($pdo);
            } catch (Throwable $e) {
                die('Koneksi PDO gagal: ' . $e->getMessage());
            }
        }

        return $pdo;
    }
}

/* =========================
   AUTO CREATE TABLE
========================= */
if (!function_exists('install_db_gege_tables')) {
    function install_db_gege_tables(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS images (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NULL,
                imgur_id VARCHAR(100) NULL,
                imgur_deletehash VARCHAR(150) NULL,
                imgur_link VARCHAR(800) NOT NULL,
                imgur_page_link VARCHAR(800) NULL,
                source_type ENUM('imgur','manual') NOT NULL DEFAULT 'manual',
                is_favorite TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_title (title),
                KEY idx_favorite (is_favorite),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sticky_notes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                note_title VARCHAR(255) NULL,
                note_text MEDIUMTEXT NOT NULL,
                note_color VARCHAR(30) NOT NULL DEFAULT '#fff1b8',
                is_pinned TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_note_title (note_title),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

/* =========================
   HELPER TAMBAHAN
========================= */
if (!function_exists('json_response')) {
    function json_response(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('read_json_body')) {
    function read_json_body(): array
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('clean_string')) {
    function clean_string(?string $value): string
    {
        return trim((string)$value);
    }
}
