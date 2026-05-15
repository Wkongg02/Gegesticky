<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| koneksi.php - DB GEGE DASHBOARD
|--------------------------------------------------------------------------
| Versi ini sudah DIHILANGKAN semua setting Imgur.
| Upload gambar memakai folder lokal /uploads.
|
| PENTING:
| - Jangan upload file ini ke GitHub public karena berisi password database.
| - Untuk hosting InfinityFree / iFastNet, DB_HOST biasanya BUKAN localhost.
| - Cek nama MySQL Host di panel hosting kamu, bentuknya biasanya:
|   sqlXXX.infinityfree.com
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Phnom_Penh');

/* =========================================================
   SETTING DATABASE HOSTING
   =========================================================
   GANTI DB_HOST sesuai MySQL Host di panel hosting kamu.
   Contoh:
   define('DB_HOST', 'sql310.infinityfree.com');
   define('DB_HOST', 'sql113.infinityfree.com');
   ========================================================= */

if (!defined('DB_HOST')) {
    define('DB_HOST', 'sqlXXX.infinityfree.com'); // GANTI INI sesuai MySQL Host kamu
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'if0_41925351_db_gege');
}

if (!defined('DB_USER')) {
    define('DB_USER', 'if0_41925351');
}

if (!defined('DB_PASS')) {
    define('DB_PASS', 'H3wGYnP3CyMHQK6');
}

/* =========================================================
   SETTING FOLDER UPLOAD GAMBAR
   ========================================================= */

if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', __DIR__ . '/uploads');
}

if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', 'uploads');
}

/* =========================================================
   KONEKSI MYSQLI
   Gunakan variable $koneksi jika kode kamu memakai mysqli.
   ========================================================= */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $koneksi = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $koneksi->set_charset('utf8mb4');
} catch (Throwable $e) {
    die('Koneksi MySQLi gagal: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

/* =========================================================
   KONEKSI PDO
   Gunakan function db() jika kode kamu memakai PDO.
   ========================================================= */

if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            try {
                $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                install_db_gege_tables($pdo);
            } catch (Throwable $e) {
                die('Koneksi PDO gagal: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
        }

        return $pdo;
    }
}

/* =========================================================
   AUTO CREATE / UPDATE TABLE
   Table:
   - images
   - sticky_notes
   - saved_scripts
   ========================================================= */

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
                source_type ENUM('imgur','manual','local') NOT NULL DEFAULT 'local',
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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS saved_scripts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                script_title VARCHAR(255) NULL,
                script_language VARCHAR(80) NOT NULL DEFAULT 'PHP',
                script_code MEDIUMTEXT NOT NULL,
                script_note TEXT NULL,
                is_favorite TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_script_title (script_title),
                KEY idx_script_language (script_language),
                KEY idx_script_favorite (is_favorite),
                KEY idx_script_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        safe_add_column($pdo, 'images', 'source_type', "
            ALTER TABLE images
            ADD COLUMN source_type ENUM('imgur','manual','local') NOT NULL DEFAULT 'local'
            AFTER imgur_page_link
        ");

        safe_add_column($pdo, 'images', 'is_favorite', "
            ALTER TABLE images
            ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0
            AFTER source_type
        ");

        safe_add_column($pdo, 'sticky_notes', 'note_title', "
            ALTER TABLE sticky_notes
            ADD COLUMN note_title VARCHAR(255) NULL
            AFTER id
        ");

        safe_add_column($pdo, 'sticky_notes', 'note_color', "
            ALTER TABLE sticky_notes
            ADD COLUMN note_color VARCHAR(30) NOT NULL DEFAULT '#fff1b8'
            AFTER note_text
        ");

        safe_add_column($pdo, 'sticky_notes', 'is_pinned', "
            ALTER TABLE sticky_notes
            ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 1
            AFTER note_color
        ");

        safe_add_column($pdo, 'sticky_notes', 'updated_at', "
            ALTER TABLE sticky_notes
            ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            AFTER created_at
        ");

        make_upload_folder();
    }
}

/* =========================================================
   SAFE ALTER TABLE HELPER
   ========================================================= */

if (!function_exists('safe_add_column')) {
    function safe_add_column(PDO $pdo, string $table, string $column, string $alterSql): void
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME = :table
              AND COLUMN_NAME = :column
        ");

        $stmt->execute([
            ':db' => DB_NAME,
            ':table' => $table,
            ':column' => $column,
        ]);

        $exists = (int)$stmt->fetchColumn();

        if ($exists === 0) {
            $pdo->exec($alterSql);
        }
    }
}

/* =========================================================
   UPLOAD FOLDER HELPER
   ========================================================= */

if (!function_exists('make_upload_folder')) {
    function make_upload_folder(): void
    {
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }

        $htaccess = UPLOAD_DIR . '/.htaccess';

        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\n");
        }
    }
}

if (!function_exists('safe_file_name')) {
    function safe_file_name(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9\.\-_]+/i', '-', $name);
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'gambar';
        }

        return $name;
    }
}

if (!function_exists('is_allowed_image')) {
    function is_allowed_image(string $tmpPath): array
    {
        $mime = mime_content_type($tmpPath);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Format gambar harus JPG, PNG, GIF, atau WEBP.');
        }

        return [$mime, $allowed[$mime]];
    }
}

/* =========================================================
   GENERAL HELPER
   ========================================================= */

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

if (!function_exists('validate_image_link')) {
    function validate_image_link(string $link): void
    {
        if ($link === '') {
            throw new InvalidArgumentException('Link gambar wajib diisi.');
        }

        if (str_starts_with($link, UPLOAD_URL . '/')) {
            return;
        }

        if (!filter_var($link, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Format link tidak valid.');
        }
    }
}

/* =========================================================
   OPTIONAL: TEST KONEKSI
   Buka: koneksi.php?test=1
   ========================================================= */

if (isset($_GET['test'])) {
    try {
        db();

        json_response([
            'success' => true,
            'message' => 'Koneksi database berhasil.',
            'database' => DB_NAME,
            'user' => DB_USER,
            'host' => DB_HOST,
            'imgur' => 'disabled',
        ]);
    } catch (Throwable $e) {
        json_response([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}
