<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Phnom_Penh');

/* =========================
   SETTING DATABASE
========================= */
const DB_HOST = 'localhost';
const DB_NAME = 'db_gege';
const DB_USER = 'root';
const DB_PASS = '';

/* =========================
   SETTING FOLDER UPLOAD
========================= */
const UPLOAD_DIR = __DIR__ . '/uploads';
const UPLOAD_URL = 'uploads';

/* =========================
   KONEKSI DATABASE
========================= */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsnServer = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';

        $server = new PDO($dsnServer, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $server->exec(
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

        install_tables($pdo);
    }

    return $pdo;
}

/* =========================
   AUTO CREATE TABLE
========================= */
function install_tables(PDO $pdo): void
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saved_scripts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            script_title VARCHAR(255) NULL,
            script_language VARCHAR(80) NOT NULL DEFAULT 'text',
            script_code MEDIUMTEXT NOT NULL,
            script_note TEXT NULL,
            is_favorite TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_script_title (script_title),
            KEY idx_script_language (script_language),
            KEY idx_favorite (is_favorite),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/* =========================
   HELPER
========================= */
function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function clean_string(?string $value): string
{
    return trim((string)$value);
}

function make_upload_folder(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }

    $htaccess = UPLOAD_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n");
    }
}

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

/* =========================
   ROUTER API
========================= */
$api = $_GET['api'] ?? '';

if ($api !== '') {
    try {
        switch ($api) {
            case 'images': {
                $q = clean_string($_GET['q'] ?? '');
                $favoriteOnly = (int)($_GET['favorite'] ?? 0);

                $where = [];
                $params = [];

                if ($q !== '') {
                    $where[] = '(title LIKE :q OR imgur_link LIKE :q OR imgur_page_link LIKE :q)';
                    $params[':q'] = '%' . $q . '%';
                }

                if ($favoriteOnly === 1) {
                    $where[] = 'is_favorite = 1';
                }

                $sql = 'SELECT * FROM images';
                if ($where) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }
                $sql .= ' ORDER BY is_favorite DESC, id DESC LIMIT 500';

                $stmt = db()->prepare($sql);
                $stmt->execute($params);

                json_response(['success' => true, 'data' => $stmt->fetchAll()]);
            }

            case 'save_image': {
                $data = read_json_body();

                $title = clean_string($data['title'] ?? '');
                $link = clean_string($data['imgur_link'] ?? '');
                $favorite = !empty($data['is_favorite']) ? 1 : 0;

                validate_image_link($link);

                $stmt = db()->prepare("
                    INSERT INTO images (title, imgur_link, imgur_page_link, source_type, is_favorite)
                    VALUES (:title, :link, :page_link, 'manual', :favorite)
                ");

                $stmt->execute([
                    ':title' => $title ?: null,
                    ':link' => $link,
                    ':page_link' => $link,
                    ':favorite' => $favorite,
                ]);

                json_response([
                    'success' => true,
                    'message' => 'Link gambar berhasil tersimpan ke database.',
                    'saved_to_database' => true,
                    'id' => db()->lastInsertId(),
                    'link' => $link,
                ]);
            }

            case 'edit_image': {
                $data = read_json_body();

                $id = (int)($data['id'] ?? 0);
                $title = clean_string($data['title'] ?? '');
                $link = clean_string($data['imgur_link'] ?? '');

                if ($id <= 0) {
                    json_response(['success' => false, 'message' => 'ID gambar tidak valid.'], 422);
                }

                validate_image_link($link);

                $stmt = db()->prepare("
                    UPDATE images
                    SET title = :title,
                        imgur_link = :link,
                        imgur_page_link = :page_link
                    WHERE id = :id
                ");

                $stmt->execute([
                    ':title' => $title ?: null,
                    ':link' => $link,
                    ':page_link' => $link,
                    ':id' => $id,
                ]);

                json_response([
                    'success' => true,
                    'message' => 'Judul dan link gambar berhasil diperbarui.',
                ]);
            }

            case 'upload_local': {
                make_upload_folder();

                if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    json_response(['success' => false, 'message' => 'File gambar belum dipilih atau gagal diupload.'], 422);
                }

                if ((int)$_FILES['image']['size'] > 10 * 1024 * 1024) {
                    json_response(['success' => false, 'message' => 'Ukuran gambar maksimal 10MB.'], 422);
                }

                [$mime, $ext] = is_allowed_image($_FILES['image']['tmp_name']);

                $title = clean_string($_POST['title'] ?? '');
                $favorite = !empty($_POST['is_favorite']) ? 1 : 0;

                $originalName = pathinfo($_FILES['image']['name'], PATHINFO_FILENAME);
                $safeName = safe_file_name($originalName);
                $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '_' . $safeName . '.' . $ext;

                $target = UPLOAD_DIR . '/' . $newName;
                $relativeLink = UPLOAD_URL . '/' . $newName;

                if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    json_response(['success' => false, 'message' => 'Gagal memindahkan file ke folder uploads.'], 500);
                }

                $stmt = db()->prepare("
                    INSERT INTO images (title, imgur_link, imgur_page_link, source_type, is_favorite)
                    VALUES (:title, :link, :page_link, 'manual', :favorite)
                ");

                $stmt->execute([
                    ':title' => $title ?: $originalName,
                    ':link' => $relativeLink,
                    ':page_link' => $relativeLink,
                    ':favorite' => $favorite,
                ]);

                json_response([
                    'success' => true,
                    'message' => 'Gambar berhasil diupload dan otomatis tersimpan ke database.',
                    'saved_to_database' => true,
                    'id' => db()->lastInsertId(),
                    'data' => [
                        'link' => $relativeLink,
                        'page_link' => $relativeLink,
                        'filename' => $newName,
                        'mime' => $mime,
                    ],
                ]);
            }

            case 'toggle_favorite': {
                $data = read_json_body();
                $id = (int)($data['id'] ?? 0);
                $favorite = !empty($data['is_favorite']) ? 1 : 0;

                if ($id <= 0) {
                    json_response(['success' => false, 'message' => 'ID gambar tidak valid.'], 422);
                }

                $stmt = db()->prepare('UPDATE images SET is_favorite = :favorite WHERE id = :id');
                $stmt->execute([':favorite' => $favorite, ':id' => $id]);

                json_response(['success' => true]);
            }

            case 'delete_image': {
                $data = read_json_body();
                $id = (int)($data['id'] ?? 0);

                if ($id <= 0) {
                    json_response(['success' => false, 'message' => 'ID gambar tidak valid.'], 422);
                }

                $stmt = db()->prepare('SELECT imgur_link FROM images WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();

                if ($row && str_starts_with((string)$row['imgur_link'], UPLOAD_URL . '/')) {
                    $filePath = __DIR__ . '/' . $row['imgur_link'];
                    if (is_file($filePath)) {
                        @unlink($filePath);
                    }
                }

                $stmt = db()->prepare('DELETE FROM images WHERE id = :id');
                $stmt->execute([':id' => $id]);

                json_response(['success' => true]);
            }

            case 'notes': {
                $q = clean_string($_GET['q'] ?? '');

                if ($q !== '') {
                    $stmt = db()->prepare("
                        SELECT * FROM sticky_notes
                        WHERE note_title LIKE :q OR note_text LIKE :q
                        ORDER BY id DESC
                        LIMIT 500
                    ");
                    $stmt->execute([':q' => '%' . $q . '%']);
                } else {
                    $stmt = db()->query("
                        SELECT * FROM sticky_notes
                        ORDER BY id DESC
                        LIMIT 500
                    ");
                }

                json_response(['success' => true, 'data' => $stmt->fetchAll()]);
            }

            case 'save_note': {
                $data = read_json_body();

                $id = (int)($data['id'] ?? 0);
                $title = clean_string($data['note_title'] ?? '');
                $text = clean_string($data['note_text'] ?? '');
                $color = clean_string($data['note_color'] ?? '#fff1b8');

                $allowedColors = ['#fff1b8', '#dff2ff', '#dff8df', '#ffdceb', '#ffe7c7', '#e6f0ff'];
                if (!in_array($color, $allowedColors, true)) {
                    $color = '#fff1b8';
                }

                if ($text === '') {
                    json_response(['success' => false, 'message' => 'Isi sticky note tidak boleh kosong.'], 422);
                }

                if ($id > 0) {
                    $stmt = db()->prepare("
                        UPDATE sticky_notes
                        SET note_title = :title, note_text = :text, note_color = :color
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':title' => $title ?: null,
                        ':text' => $text,
                        ':color' => $color,
                        ':id' => $id,
                    ]);
                } else {
                    $stmt = db()->prepare("
                        INSERT INTO sticky_notes (note_title, note_text, note_color)
                        VALUES (:title, :text, :color)
                    ");
                    $stmt->execute([
                        ':title' => $title ?: null,
                        ':text' => $text,
                        ':color' => $color,
                    ]);

                    $id = (int)db()->lastInsertId();
                }

                json_response(['success' => true, 'id' => $id]);
            }

            case 'delete_note': {
                $data = read_json_body();
                $id = (int)($data['id'] ?? 0);

                if ($id <= 0) {
                    json_response(['success' => false, 'message' => 'ID note tidak valid.'], 422);
                }

                $stmt = db()->prepare('DELETE FROM sticky_notes WHERE id = :id');
                $stmt->execute([':id' => $id]);

                json_response(['success' => true]);
            }


            case 'scripts': {
                $q = clean_string($_GET['q'] ?? '');

                if ($q !== '') {
                    $stmt = db()->prepare("
                        SELECT * FROM saved_scripts
                        WHERE script_title LIKE :q OR script_language LIKE :q OR script_code LIKE :q OR script_note LIKE :q
                        ORDER BY is_favorite DESC, id DESC
                        LIMIT 500
                    ");
                    $stmt->execute([':q' => '%' . $q . '%']);
                } else {
                    $stmt = db()->query("
                        SELECT * FROM saved_scripts
                        ORDER BY is_favorite DESC, id DESC
                        LIMIT 500
                    ");
                }

                json_response(['success' => true, 'data' => $stmt->fetchAll()]);
            }

            case 'save_script': {
                $data = read_json_body();

                $id = (int)($data['id'] ?? 0);
                $title = clean_string($data['script_title'] ?? '');
                $language = clean_string($data['script_language'] ?? 'text');
                $scriptCode = (string)($data['script_code'] ?? '');
                $note = clean_string($data['script_note'] ?? '');
                $favorite = !empty($data['is_favorite']) ? 1 : 0;

                if (trim($scriptCode) === '') {
                    json_response(['success' => false, 'message' => 'Isi script tidak boleh kosong.'], 422);
                }

                if ($language === '') {
                    $language = 'text';
                }

                if ($id > 0) {
                    $stmt = db()->prepare("
                        UPDATE saved_scripts
                        SET script_title = :title,
                            script_language = :language,
                            script_code = :code,
                            script_note = :note,
                            is_favorite = :favorite
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':title' => $title ?: null,
                        ':language' => $language,
                        ':code' => $scriptCode,
                        ':note' => $note ?: null,
                        ':favorite' => $favorite,
                        ':id' => $id,
                    ]);
                } else {
                    $stmt = db()->prepare("
                        INSERT INTO saved_scripts (script_title, script_language, script_code, script_note, is_favorite)
                        VALUES (:title, :language, :code, :note, :favorite)
                    ");
                    $stmt->execute([
                        ':title' => $title ?: null,
                        ':language' => $language,
                        ':code' => $scriptCode,
                        ':note' => $note ?: null,
                        ':favorite' => $favorite,
                    ]);
                    $id = (int)db()->lastInsertId();
                }

                json_response(['success' => true, 'id' => $id]);
            }

            case 'delete_script': {
                $data = read_json_body();
                $id = (int)($data['id'] ?? 0);

                if ($id <= 0) {
                    json_response(['success' => false, 'message' => 'ID script tidak valid.'], 422);
                }

                $stmt = db()->prepare('DELETE FROM saved_scripts WHERE id = :id');
                $stmt->execute([':id' => $id]);

                json_response(['success' => true]);
            }

            case 'toggle_script_favorite': {
                $data = read_json_body();
                $id = (int)($data['id'] ?? 0);
                $favorite = !empty($data['is_favorite']) ? 1 : 0;

                if ($id <= 0) {
                    json_response(['success' => false, 'message' => 'ID script tidak valid.'], 422);
                }

                $stmt = db()->prepare('UPDATE saved_scripts SET is_favorite = :favorite WHERE id = :id');
                $stmt->execute([':favorite' => $favorite, ':id' => $id]);

                json_response(['success' => true]);
            }

            default:
                json_response(['success' => false, 'message' => 'API tidak ditemukan.'], 404);
        }
    } catch (Throwable $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>db_gege - Gallery Popup Edit</title>

    <style>
        :root {
            --bg: #08070d;
            --bg-soft: #11101a;
            --panel: #151320;
            --line: #332e49;
            --text: #f7f0ff;
            --muted: #b5aec6;
            --pink: #e8a6d8;
            --blue: #a9dcff;
            --yellow: #ffef38;
            --danger: #ff6b8b;
            --green: #4aaa63;
            --shadow: 0 18px 50px rgba(0, 0, 0, .36);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at 22% 0%, rgba(232, 166, 216, .12), transparent 28%),
                radial-gradient(circle at 80% 8%, rgba(169, 220, 255, .10), transparent 32%),
                linear-gradient(180deg, #11101a 0%, #08070d 45%, #07060b 100%);
        }

        button, input, textarea {
            font-family: inherit;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 60;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px 12px;
            background: rgba(17, 16, 26, .96);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(12px);
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .gallery-toolbar {
            display: flex;
        }

        .notes-toolbar {
            display: none;
            width: 100%;
        }

        .notes-toolbar .notes-search-top,
        .scripts-toolbar .notes-search-top {
            flex: 1;
            min-width: 280px;
            height: 34px;
            color: #17131e;
            background: #fff;
            border: 1px solid rgba(0,0,0,.25);
            border-radius: 10px;
            outline: none;
            padding: 0 12px;
            font-size: 14px;
        }

        .notes-toolbar .notes-search-top:focus,
        .scripts-toolbar .notes-search-top:focus {
            box-shadow: 0 0 0 4px rgba(255, 213, 100, .20);
        }

        .field {
            height: 34px;
            color: var(--text);
            background: #0a0912;
            border: 1px solid #36314c;
            border-radius: 12px;
            outline: none;
            padding: 0 12px;
            font-size: 14px;
            transition: .15s ease;
        }

        .field:focus,
        .note-input:focus,
        .popup-input:focus {
            border-color: var(--pink);
            box-shadow: 0 0 0 4px rgba(232, 166, 216, .13);
        }

        .field-small {
            width: 190px;
        }

        .field-search {
            width: 190px;
        }

        .btn {
            height: 34px;
            border: 0;
            border-radius: 13px;
            padding: 0 15px;
            color: #fff;
            cursor: pointer;
            font-weight: 800;
            background: #2d293f;
            box-shadow: 0 8px 18px rgba(0, 0, 0, .16);
            transition: transform .12s ease, opacity .12s ease, filter .12s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.05);
        }

        .btn-save {
            background: linear-gradient(135deg, #e8a6d8, #a9dcff);
        }

        .btn-img,
        .btn-note {
            background: linear-gradient(135deg, #e9a4db, #9fd6ff);
        }

        .btn-menu-active {
            box-shadow: 0 0 0 3px rgba(255, 239, 56, .20), 0 8px 18px rgba(0, 0, 0, .16);
            filter: brightness(1.08);
        }

        .btn-add-note {
            color: #16121e;
            background: #ffd564;
        }

        .btn-dark {
            background: #2b263c;
        }

        .btn-delete {
            background: rgba(83, 40, 56, .95);
            color: #ffd4df;
        }

        .btn-green {
            color: #101710;
            background: #b9f7c6;
        }

        .mini-check {
            width: 13px;
            height: 13px;
            accent-color: var(--yellow);
        }

        .star-button {
            width: 32px;
            min-width: 32px;
            padding: 0;
            font-size: 20px;
            line-height: 1;
            color: var(--yellow);
            background: transparent;
            box-shadow: none;
        }

        .star-button.off {
            filter: grayscale(1);
            opacity: .55;
        }

        .layout {
            width: 100%;
            padding: 0 12px 60px;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        .gallery-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 0 12px;
            color: #ddd6ff;
            font-size: 13px;
        }

        .gallery-head strong {
            color: #fff;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, 370px);
            gap: 24px;
            align-items: start;
        }

        .image-card {
            width: 370px;
            min-height: 700px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border-radius: 18px;
            border: 1px solid #342f4d;
            background: linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.018));
            box-shadow: var(--shadow);
        }

        .card-menu {
            display: grid;
            grid-template-columns: 38px 1fr 1fr 38px;
            gap: 8px;
            padding: 10px;
            border-bottom: 1px solid rgba(255,255,255,.06);
            background: rgba(8, 7, 13, .72);
        }

        .card-menu .btn {
            height: 32px;
            padding: 0 8px;
            border-radius: 12px;
            font-size: 13px;
        }

        .image-preview {
            position: relative;
            width: 100%;
            height: 580px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: zoom-in;
            background: transparent;
            border-bottom: 1px solid #332e49;
        }

        .image-preview::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: var(--img-bg);
            background-size: cover;
            background-position: center;
            filter: blur(18px);
            transform: scale(1.12);
            opacity: .78;
        }

        .image-preview img {
            position: relative;
            z-index: 1;
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            transition: transform .15s ease;
        }

        .image-preview:hover img {
            transform: scale(1.018);
        }

        .image-fallback {
            padding: 18px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .image-title-box {
            padding: 13px 14px 16px;
        }

        .image-title {
            margin: 0;
            min-width: 0;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            color: #fff;
            font-size: 18px;
            font-weight: 950;
        }

        .image-link-small {
            display: block;
            margin-top: 7px;
            overflow: hidden;
            color: #c8dcff;
            text-decoration: none;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-size: 12px;
        }

        .empty-box {
            min-height: 220px;
            display: grid;
            place-items: center;
            padding: 25px;
            color: var(--muted);
            text-align: center;
            border: 1px dashed #39344d;
            border-radius: 18px;
            background: rgba(255,255,255,.025);
        }

        /* =========================
           IMAGE POPUP
        ========================= */
        .image-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 130;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(0, 0, 0, .52);
            backdrop-filter: blur(10px);
        }

        .image-modal-backdrop.open {
            display: flex;
        }

        .image-modal {
            width: min(1180px, 96vw);
            max-height: 92vh;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 24px;
            background: rgba(18, 16, 29, .82);
            box-shadow: 0 24px 90px rgba(0, 0, 0, .62);
        }

        .popup-image-area {
            min-height: 680px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px;
            overflow: auto;
            background:
                linear-gradient(45deg, rgba(255,255,255,.035) 25%, transparent 25%),
                linear-gradient(-45deg, rgba(255,255,255,.035) 25%, transparent 25%),
                rgba(5, 4, 9, .62);
            background-size: 20px 20px;
        }

        .popup-image-area img {
            max-width: 100%;
            max-height: 84vh;
            object-fit: contain;
            border-radius: 16px;
            box-shadow: 0 12px 36px rgba(0,0,0,.35);
        }

        .popup-panel {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 16px;
            border-left: 1px solid rgba(255,255,255,.13);
            background: rgba(13, 12, 20, .86);
        }

        .popup-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .popup-top h2 {
            margin: 0;
            font-size: 18px;
        }

        .popup-label {
            color: #ddd6ff;
            font-size: 13px;
            font-weight: 850;
        }

        .popup-input {
            width: 100%;
            min-height: 38px;
            outline: none;
            color: #fff;
            background: #090811;
            border: 1px solid #37324d;
            border-radius: 12px;
            padding: 9px 11px;
            font-size: 14px;
        }

        .popup-link-preview {
            display: block;
            overflow: hidden;
            color: #c8dcff;
            text-decoration: none;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12px;
        }

        .popup-actions {
            display: grid;
            gap: 9px;
            margin-top: auto;
        }

        .popup-actions-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 9px;
        }


        /* =========================
           STICKY NOTES
        ========================= */
        .notes-top {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 10px 0 14px;
            border-top: 1px solid rgba(255,255,255,.03);
        }

        .notes-search {
            flex: 1;
            height: 32px;
            border: 1px solid rgba(0,0,0,.25);
            border-radius: 10px;
            padding: 0 12px;
            outline: none;
            color: #17131e;
            background: #fff;
            font-size: 14px;
        }

        .notes-grid {
            column-count: 5;
            column-gap: 12px;
        }

        .note-card {
            width: 100%;
            display: inline-block;
            break-inside: avoid;
            margin: 0 0 12px;
            min-height: 182px;
            border-radius: 13px;
            border: 1px solid rgba(0, 0, 0, .18);
            overflow: hidden;
            color: #080812;
            box-shadow: 0 10px 25px rgba(0, 0, 0, .23);
            transition: transform .12s ease, box-shadow .12s ease;
        }

        .note-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 32px rgba(0, 0, 0, .30);
        }

        .note-inner {
            padding: 12px 12px 10px;
        }

        .note-title {
            margin: 0;
            color: #05050a;
            font-size: 15px;
            font-weight: 950;
            letter-spacing: -.2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-transform: uppercase;
        }

        .note-line {
            height: 2px;
            margin: 10px 0 12px;
            border-top: 2px dashed rgba(0, 0, 0, .25);
        }

        .note-text {
            min-height: 92px;
            margin: 0;
            color: #000;
            font-family: "Courier New", monospace;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.45;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .note-foot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }

        .saved {
            color: #4ba064;
            font-size: 12px;
            font-weight: 700;
        }

        .note-actions {
            display: flex;
            gap: 8px;
        }

        .icon-btn {
            width: 23px;
            height: 23px;
            display: grid;
            place-items: center;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
            color: #322717;
            background: rgba(0,0,0,.13);
            font-size: 13px;
            padding: 0;
        }

        .icon-btn:hover {
            background: rgba(0,0,0,.22);
            transform: translateY(-1px);
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(0, 0, 0, .58);
            backdrop-filter: blur(6px);
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal {
            width: min(560px, 100%);
            border: 1px solid #38324f;
            border-radius: 22px;
            background: #151320;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 17px;
            border-bottom: 1px solid #312c46;
            background: rgba(255,255,255,.03);
        }

        .modal-head h2 {
            margin: 0;
            font-size: 18px;
        }

        .modal-body {
            padding: 16px;
        }

        .note-input {
            width: 100%;
            border: 1px solid #37324d;
            border-radius: 14px;
            outline: none;
            background: #0b0a13;
            color: #fff;
            padding: 11px 12px;
            font-size: 14px;
        }

        textarea.note-input {
            min-height: 230px;
            resize: vertical;
            line-height: 1.5;
        }

        .modal-label {
            display: block;
            margin: 12px 0 7px;
            color: #d8d0e8;
            font-weight: 800;
            font-size: 13px;
        }

        .color-row {
            display: flex;
            gap: 9px;
            margin: 11px 0 4px;
            flex-wrap: wrap;
        }

        .color-dot {
            width: 28px;
            height: 28px;
            border: 2px solid rgba(255,255,255,.55);
            border-radius: 999px;
            cursor: pointer;
        }

        .color-dot.active {
            outline: 3px solid #fff;
            outline-offset: 2px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 16px;
        }

        .toast {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 200;
            display: none;
            max-width: 380px;
            padding: 12px 14px;
            color: #14101d;
            background: #fff;
            border-radius: 14px;
            box-shadow: var(--shadow);
            font-weight: 800;
        }

        @media (max-width: 1600px) {
            .notes-grid { column-count: 4; }
        }

        @media (max-width: 1250px) {
            .notes-grid { column-count: 3; }
        }

        @media (max-width: 980px) {
            .topbar { flex-wrap: wrap; }

            .field-small,
            .field-search {
                width: calc(50% - 8px);
            }

            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            }

            .image-card {
                width: 100%;
            }

            .image-modal {
                grid-template-columns: 1fr;
                overflow-y: auto;
            }

            .popup-image-area {
                min-height: 360px;
            }

            .popup-panel {
                border-left: 0;
                border-top: 1px solid rgba(255,255,255,.13);
            }

            .notes-grid { column-count: 2; }
        }

        @media (max-width: 560px) {
            .field-small,
            .field-search {
                width: 100%;
            }

            .notes-top {
                align-items: stretch;
                flex-direction: column;
            }

            .notes-grid { column-count: 1; }

            .image-preview {
                height: 420px;
            }

            .card-menu {
                grid-template-columns: 38px 1fr;
            }
        }
    
        /* =========================
           PROFESSIONAL UPDATE
           Grey + pink gradient, glass toolbar, falling stars, script menu
        ========================= */
        body {
            background:
                radial-gradient(circle at 18% 4%, rgba(255, 132, 204, .32), transparent 30%),
                radial-gradient(circle at 86% 8%, rgba(255, 255, 255, .24), transparent 22%),
                linear-gradient(135deg, #ececf1 0%, #d9d9df 33%, #f3c8de 68%, #e7a8cc 100%);
            color: #fdf7ff;
        }

        .topbar {
            top: 12px;
            width: calc(100% - 24px);
            margin: 0 auto;
            border: 1px solid rgba(255,255,255,.34);
            border-radius: 22px;
            background: rgba(37, 34, 45, .68);
            box-shadow: 0 22px 60px rgba(81, 55, 77, .22);
        }

        .toolbar-group {
            width: 100%;
        }

        .field {
            border: 1px solid rgba(255,255,255,.28);
            background: rgba(16, 13, 24, .76);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
        }

        .btn {
            border: 1px solid rgba(255,255,255,.28);
            background: rgba(37, 34, 50, .86);
            box-shadow: 0 12px 26px rgba(58, 41, 65, .22);
            letter-spacing: .1px;
        }

        .btn-save,
        .btn-img,
        .btn-note,
        .btn-script,
        .btn-add-note,
        .btn-add-script {
            color: #221725;
            background: linear-gradient(135deg, #ffd7ec 0%, #e6b8ff 44%, #bfe6ff 100%);
        }

        .btn-menu-active {
            box-shadow: 0 0 0 3px rgba(255, 221, 243, .42), 0 12px 26px rgba(58, 41, 65, .22);
        }

        .btn-ghost-pink {
            color: #fff;
            background: rgba(255,255,255,.14);
        }

        .scripts-toolbar {
            display: none;
            width: 100%;
        }

        .section-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            margin: 18px 0 14px;
            padding: 16px 18px;
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 24px;
            color: #302131;
            background: rgba(255,255,255,.38);
            backdrop-filter: blur(16px);
            box-shadow: 0 18px 38px rgba(73, 49, 72, .16);
        }

        .section-title-row h1 {
            margin: 0;
            font-size: 22px;
            letter-spacing: -.4px;
        }

        .section-title-row p {
            margin: 5px 0 0;
            color: rgba(48, 33, 49, .72);
            font-size: 13px;
            font-weight: 700;
        }

        .gallery-head {
            color: #352437;
            background: rgba(255,255,255,.34);
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 18px;
            padding: 13px 16px;
            backdrop-filter: blur(14px);
        }

        .gallery-head strong {
            color: #6b2e5a;
        }

        .image-card,
        .modal,
        .image-modal,
        .script-card {
            border: 1px solid rgba(255,255,255,.36);
            background: rgba(25, 21, 33, .72);
            backdrop-filter: blur(14px);
            box-shadow: 0 24px 60px rgba(75, 48, 74, .25);
        }

        .card-menu {
            background: rgba(255,255,255,.10);
        }

        .image-title-box {
            background: rgba(11, 10, 17, .56);
        }

        .notes-grid {
            margin-top: 14px;
        }

        .note-card {
            box-shadow: 0 18px 38px rgba(88, 54, 80, .22);
        }

        .script-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 18px;
            margin-top: 14px;
        }

        .script-card {
            min-height: 360px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border-radius: 24px;
        }

        .script-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 15px 15px 12px;
            border-bottom: 1px solid rgba(255,255,255,.12);
        }

        .script-title {
            margin: 0;
            color: #fff;
            font-size: 17px;
            font-weight: 950;
            letter-spacing: -.2px;
        }

        .script-meta {
            margin-top: 7px;
            display: inline-flex;
            gap: 8px;
            align-items: center;
            color: #ffe7f4;
            font-size: 12px;
            font-weight: 850;
        }

        .language-pill {
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 999px;
            padding: 4px 9px;
            background: rgba(255,255,255,.12);
        }

        .script-code-preview {
            flex: 1;
            margin: 0;
            padding: 15px;
            max-height: 260px;
            overflow: auto;
            color: #f8f0ff;
            background: rgba(5, 4, 9, .58);
            font-family: Consolas, "Courier New", monospace;
            font-size: 12.5px;
            line-height: 1.55;
            white-space: pre-wrap;
        }

        .script-note {
            margin: 0;
            padding: 10px 15px;
            color: #f9dff0;
            font-size: 13px;
            border-top: 1px solid rgba(255,255,255,.10);
            background: rgba(255,255,255,.06);
        }

        .script-actions {
            display: grid;
            grid-template-columns: 1fr 1fr 38px;
            gap: 9px;
            padding: 12px;
            background: rgba(255,255,255,.08);
        }

        .script-actions .btn {
            height: 32px;
            padding: 0 10px;
        }

        .script-input {
            width: 100%;
            border: 1px solid #37324d;
            border-radius: 14px;
            outline: none;
            background: #0b0a13;
            color: #fff;
            padding: 11px 12px;
            font-size: 14px;
        }

        textarea.script-input {
            min-height: 330px;
            resize: vertical;
            line-height: 1.5;
            font-family: Consolas, "Courier New", monospace;
        }

        .script-form-grid {
            display: grid;
            grid-template-columns: 1.1fr .7fr;
            gap: 12px;
        }

        .starfall {
            pointer-events: none;
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
        }

        .starfall span {
            position: absolute;
            top: -28px;
            width: 5px;
            height: 5px;
            border-radius: 999px;
            background: rgba(255,255,255,.95);
            box-shadow: 0 0 10px rgba(255, 185, 225, .95), 0 0 22px rgba(255, 255, 255, .75);
            animation: starDrop linear infinite;
        }

        .starfall span::after {
            content: "";
            position: absolute;
            width: 2px;
            height: 28px;
            left: 1.5px;
            bottom: 4px;
            border-radius: 999px;
            background: linear-gradient(to top, rgba(255,255,255,.68), transparent);
        }

        @keyframes starDrop {
            0% {
                transform: translate3d(0, -40px, 0) rotate(18deg);
                opacity: 0;
            }
            10% {
                opacity: .95;
            }
            100% {
                transform: translate3d(-90px, 112vh, 0) rotate(18deg);
                opacity: 0;
            }
        }

        .layout {
            position: relative;
            z-index: 2;
        }

        .topbar {
            position: sticky;
            z-index: 60;
        }

        .toast {
            position: fixed;
            z-index: 200;
        }

        .modal-backdrop,
        .image-modal-backdrop {
            position: fixed;
            z-index: 100;
        }

        @media (max-width: 720px) {
            .script-form-grid {
                grid-template-columns: 1fr;
            }

            .script-grid {
                grid-template-columns: 1fr;
            }

            .topbar {
                top: 6px;
                width: calc(100% - 12px);
                border-radius: 18px;
            }
        }

    
        /* =========================
           FINAL POLISH UPDATE
           Darker pink-grey dashboard, compact menus, clean sections
        ========================= */
        body {
            background:
                radial-gradient(circle at 18% 6%, rgba(255, 115, 190, .20), transparent 26%),
                radial-gradient(circle at 82% 10%, rgba(255, 214, 238, .11), transparent 25%),
                linear-gradient(135deg, #17151d 0%, #27232d 34%, #473145 68%, #6f3f61 100%) !important;
            color: #fff7ff;
        }

        .topbar {
            top: 8px !important;
            width: calc(100% - 18px) !important;
            min-height: 46px;
            padding: 7px 9px !important;
            border-radius: 16px !important;
            background: rgba(20, 18, 27, .80) !important;
            border: 1px solid rgba(255,255,255,.16) !important;
            box-shadow: 0 14px 36px rgba(0,0,0,.28) !important;
        }

        .toolbar-group {
            gap: 7px !important;
            width: auto !important;
        }

        .gallery-toolbar,
        .notes-toolbar,
        .scripts-toolbar {
            align-items: center;
            width: auto !important;
        }

        .field,
        .notes-toolbar .notes-search-top,
        .scripts-toolbar .notes-search-top {
            height: 30px !important;
            border-radius: 10px !important;
            font-size: 12px !important;
            padding: 0 10px !important;
            background: rgba(10, 9, 14, .78) !important;
            color: #fff7ff !important;
            border: 1px solid rgba(255,255,255,.14) !important;
        }

        .field::placeholder,
        .notes-toolbar .notes-search-top::placeholder,
        .scripts-toolbar .notes-search-top::placeholder {
            color: rgba(255,247,255,.58);
        }

        .field-small {
            width: 160px !important;
        }

        .field-search {
            width: 170px !important;
        }

        .notes-toolbar .notes-search-top,
        .scripts-toolbar .notes-search-top {
            flex: 0 0 260px !important;
            width: 260px !important;
            min-width: 210px !important;
            max-width: 260px !important;
        }

        .btn {
            height: 30px !important;
            padding: 0 11px !important;
            border-radius: 11px !important;
            font-size: 12px !important;
            font-weight: 900 !important;
            background: rgba(37, 33, 48, .86) !important;
            border: 1px solid rgba(255,255,255,.16) !important;
            box-shadow: 0 8px 18px rgba(0,0,0,.20) !important;
        }

        .btn-save,
        .btn-img,
        .btn-note,
        .btn-script,
        .btn-add-note,
        .btn-add-script {
            color: #211520 !important;
            background: linear-gradient(135deg, #ffcae7 0%, #ddb5ff 48%, #b8e2ff 100%) !important;
        }

        .star-button {
            width: 28px !important;
            min-width: 28px !important;
            padding: 0 !important;
            font-size: 17px !important;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
        }

        .mini-check {
            width: 12px;
            height: 12px;
        }

        .layout {
            padding-top: 10px !important;
        }

        .section-title-row {
            display: none !important;
        }

        .gallery-head {
            display: block !important;
            width: fit-content;
            margin: 0 0 10px !important;
            padding: 5px 10px !important;
            border-radius: 999px !important;
            color: rgba(255,247,255,.78) !important;
            background: rgba(14, 12, 20, .42) !important;
            border: 1px solid rgba(255,255,255,.11) !important;
            box-shadow: none !important;
            backdrop-filter: blur(8px);
            font-size: 12px !important;
        }

        .gallery-head span:nth-child(2) {
            display: none !important;
        }

        .notes-grid,
        .script-grid {
            margin-top: 8px !important;
        }

        .empty-box {
            display: none !important;
        }

        .clean-empty {
            width: fit-content;
            margin: 14px 0;
            padding: 8px 12px;
            border-radius: 999px;
            color: rgba(255,247,255,.62);
            background: rgba(11, 10, 17, .34);
            border: 1px solid rgba(255,255,255,.09);
            font-size: 12px;
            font-weight: 800;
        }

        .image-card,
        .script-card,
        .modal,
        .image-modal {
            background: rgba(18, 16, 25, .74) !important;
            border: 1px solid rgba(255,255,255,.13) !important;
            box-shadow: 0 18px 46px rgba(0,0,0,.32) !important;
        }

        .note-card {
            box-shadow: 0 16px 34px rgba(0,0,0,.24) !important;
            border: 1px solid rgba(0,0,0,.16) !important;
        }

        .card-menu,
        .script-actions {
            background: rgba(255,255,255,.055) !important;
        }

        .image-title-box {
            background: rgba(8, 7, 12, .58) !important;
        }

        .starfall span {
            width: 4px !important;
            height: 4px !important;
            opacity: .68;
        }

        .starfall span::after {
            height: 22px !important;
        }

        @media (max-width: 760px) {
            .field-small,
            .field-search,
            .notes-toolbar .notes-search-top,
            .scripts-toolbar .notes-search-top {
                width: 100% !important;
                max-width: none !important;
                flex: 1 1 100% !important;
            }

            .toolbar-group {
                width: 100% !important;
            }
        }

    </style>
</head>

<body>
    <div class="starfall" aria-hidden="true">
        <span style="left:4%; animation-duration:9s; animation-delay:-1s;"></span>
        <span style="left:9%; animation-duration:12s; animation-delay:-7s;"></span>
        <span style="left:15%; animation-duration:10s; animation-delay:-4s;"></span>
        <span style="left:22%; animation-duration:14s; animation-delay:-8s;"></span>
        <span style="left:28%; animation-duration:11s; animation-delay:-2s;"></span>
        <span style="left:34%; animation-duration:15s; animation-delay:-10s;"></span>
        <span style="left:41%; animation-duration:9s; animation-delay:-5s;"></span>
        <span style="left:47%; animation-duration:13s; animation-delay:-3s;"></span>
        <span style="left:54%; animation-duration:10s; animation-delay:-6s;"></span>
        <span style="left:60%; animation-duration:16s; animation-delay:-12s;"></span>
        <span style="left:66%; animation-duration:11s; animation-delay:-7s;"></span>
        <span style="left:73%; animation-duration:14s; animation-delay:-4s;"></span>
        <span style="left:79%; animation-duration:10s; animation-delay:-8s;"></span>
        <span style="left:85%; animation-duration:12s; animation-delay:-2s;"></span>
        <span style="left:91%; animation-duration:15s; animation-delay:-9s;"></span>
        <span style="left:96%; animation-duration:9s; animation-delay:-6s;"></span>
    </div>
    <form class="topbar" id="imageForm">
        <div class="toolbar-group gallery-toolbar" id="galleryToolbar">
            <input class="field field-small" id="linkInput" placeholder="Link SS">
            <input class="field field-small" id="titleInput" placeholder="Judul">
            <button class="btn btn-save" type="submit">Save</button>

            <input class="field field-search" id="imageSearch" placeholder="Cari gambar di sini">

            <input class="mini-check" type="checkbox" id="favoriteOnly" title="Tampilkan favorit saja">
            <button class="btn star-button off" type="button" id="newFavorite" title="Tandai favorite untuk gambar baru">★</button>

            <button class="btn btn-img" type="button" id="uploadBtn">⬆ Upload Gambar</button>
            <button class="btn btn-img" type="button" id="showGalleryBtn">🖼 Gambar</button>
            <button class="btn btn-note" type="button" id="showNotesBtn">🟨 Sticky Notes</button>
            <button class="btn btn-script" type="button" id="showScriptsBtn">⌘ Script</button>
        </div>

        <div class="toolbar-group notes-toolbar" id="notesToolbar">
            <input class="notes-search-top" id="noteSearch" placeholder="Cari Notes di sini">
            <button class="btn btn-add-note" id="addNoteBtn" type="button">➕ Note</button>
            <button class="btn btn-script" type="button" id="showScriptsFromNotesBtn">⌘ Script</button>
            <button class="btn btn-img" type="button" id="backGalleryFromNotesBtn">🖼 Gambar</button>
        </div>

        <div class="toolbar-group scripts-toolbar" id="scriptsToolbar">
            <input class="notes-search-top" id="scriptSearch" placeholder="Cari Script di sini">
            <button class="btn btn-add-script" id="addScriptBtn" type="button">＋ Script</button>
            <button class="btn btn-note" type="button" id="showNotesFromScriptsBtn">🟨 Sticky Notes</button>
            <button class="btn btn-img" type="button" id="backGalleryFromScriptsBtn">🖼 Gambar</button>
        </div>

        <input type="file" id="fileInput" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
    </form>

    <main class="layout">
        <section class="section active" id="gallerySection">
            <div class="gallery-head">
                <span id="imageCount">Memuat gambar...</span>
            </div>

            <div class="gallery-grid" id="galleryGrid"></div>
        </section>

        <section class="section" id="notesSection">
            <div class="notes-grid" id="notesGrid"></div>
        </section>

        <section class="section" id="scriptsSection">
            <div class="script-grid" id="scriptsGrid"></div>
        </section>
    </main>

    <!-- POPUP GAMBAR -->
    <div class="image-modal-backdrop" id="imageModal">
        <div class="image-modal">
            <div class="popup-image-area">
                <img id="popupImage" src="" alt="Preview gambar">
            </div>

            <div class="popup-panel">
                <div class="popup-top">
                    <h2>Detail Gambar</h2>
                    <button class="btn btn-dark" type="button" id="closeImageModalBtn">Tutup</button>
                </div>

                <input type="hidden" id="popupImageId">

                <label class="popup-label">Judul</label>
                <input class="popup-input" id="popupTitleInput" placeholder="Judul gambar">

                <label class="popup-label">Link Gambar</label>
                <input class="popup-input" id="popupLinkInput" placeholder="uploads/namafile.jpg atau link https://">

                <a href="#" target="_blank" class="popup-link-preview" id="popupLinkPreview">-</a>

                <div class="popup-actions">
                    <button class="btn btn-green" type="button" id="saveImageEditBtn">Simpan Edit</button>

                    <div class="popup-actions-row">
                        <button class="btn btn-dark" type="button" id="copyImageLinkBtn">Copy Link</button>
                        <button class="btn btn-dark" type="button" id="openImageLinkBtn">Buka Link</button>
                    </div>

                    <button class="btn btn-delete" type="button" id="deleteImageFromPopupBtn">Hapus Gambar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL NOTE -->
    <div class="modal-backdrop" id="noteModal">
        <div class="modal">
            <div class="modal-head">
                <h2 id="modalTitle">Tambah Sticky Note</h2>
                <button class="btn btn-dark" type="button" id="closeModalBtn">Tutup</button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="noteId">

                <label class="modal-label">Judul Note</label>
                <input class="note-input" id="noteTitleInput" placeholder="Contoh: SCATER ADE">

                <label class="modal-label">Isi Note</label>
                <textarea class="note-input" id="noteTextInput" placeholder="Tulis isi note di sini..."></textarea>

                <label class="modal-label">Warna Note</label>
                <div class="color-row" id="colorRow">
                    <button type="button" class="color-dot active" data-color="#fff1b8" style="background:#fff1b8"></button>
                    <button type="button" class="color-dot" data-color="#dff2ff" style="background:#dff2ff"></button>
                    <button type="button" class="color-dot" data-color="#dff8df" style="background:#dff8df"></button>
                    <button type="button" class="color-dot" data-color="#ffdceb" style="background:#ffdceb"></button>
                    <button type="button" class="color-dot" data-color="#ffe7c7" style="background:#ffe7c7"></button>
                    <button type="button" class="color-dot" data-color="#e6f0ff" style="background:#e6f0ff"></button>
                </div>

                <div class="modal-actions">
                    <button class="btn btn-dark" type="button" id="resetNoteBtn">Reset</button>
                    <button class="btn btn-save" type="button" id="saveNoteBtn">Simpan Note</button>
                </div>
            </div>
        </div>
    </div>


    <!-- MODAL SCRIPT -->
    <div class="modal-backdrop" id="scriptModal">
        <div class="modal" style="width:min(920px, 100%);">
            <div class="modal-head">
                <h2 id="scriptModalTitle">Tambah Script</h2>
                <button class="btn btn-dark" type="button" id="closeScriptModalBtn">Tutup</button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="scriptId">

                <div class="script-form-grid">
                    <div>
                        <label class="modal-label">Judul Script</label>
                        <input class="script-input" id="scriptTitleInput" placeholder="Contoh: Login Form PHP">
                    </div>

                    <div>
                        <label class="modal-label">Bahasa / Tipe</label>
                        <input class="script-input" id="scriptLanguageInput" placeholder="php, html, javascript, sql">
                    </div>
                </div>

                <label class="modal-label">Isi Kodingan Script</label>
                <textarea class="script-input" id="scriptCodeInput" spellcheck="false" placeholder="Tempel script/kodingan di sini..."></textarea>

                <label class="modal-label">Catatan</label>
                <input class="script-input" id="scriptNoteInput" placeholder="Catatan singkat, fungsi script, atau cara pakai">

                <label style="display:flex;align-items:center;gap:8px;margin-top:12px;color:#f7edff;font-weight:800;">
                    <input type="checkbox" id="scriptFavoriteInput" style="accent-color:#ffcae5;">
                    Tandai favorit
                </label>

                <div class="modal-actions">
                    <button class="btn btn-dark" type="button" id="resetScriptBtn">Reset</button>
                    <button class="btn btn-save" type="button" id="saveScriptBtn">Simpan Script</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const api = (name) => `?api=${name}`;

        const gallerySection = document.getElementById('gallerySection');
        const notesSection = document.getElementById('notesSection');
        const scriptsSection = document.getElementById('scriptsSection');

        const imageForm = document.getElementById('imageForm');
        const linkInput = document.getElementById('linkInput');
        const titleInput = document.getElementById('titleInput');
        const imageSearch = document.getElementById('imageSearch');
        const favoriteOnly = document.getElementById('favoriteOnly');
        const newFavorite = document.getElementById('newFavorite');
        const galleryToolbar = document.getElementById('galleryToolbar');
        const notesToolbar = document.getElementById('notesToolbar');
        const scriptsToolbar = document.getElementById('scriptsToolbar');
        const showGalleryBtn = document.getElementById('showGalleryBtn');
        const backGalleryFromNotesBtn = document.getElementById('backGalleryFromNotesBtn');
        const backGalleryFromScriptsBtn = document.getElementById('backGalleryFromScriptsBtn');
        const uploadBtn = document.getElementById('uploadBtn');
        const fileInput = document.getElementById('fileInput');
        const galleryGrid = document.getElementById('galleryGrid');
        const imageCount = document.getElementById('imageCount');

        const showNotesBtn = document.getElementById('showNotesBtn');
        const showNotesFromScriptsBtn = document.getElementById('showNotesFromScriptsBtn');
        const addNoteBtn = document.getElementById('addNoteBtn');
        const noteSearch = document.getElementById('noteSearch');
        const notesGrid = document.getElementById('notesGrid');

        const showScriptsBtn = document.getElementById('showScriptsBtn');
        const showScriptsFromNotesBtn = document.getElementById('showScriptsFromNotesBtn');
        const addScriptBtn = document.getElementById('addScriptBtn');
        const scriptSearch = document.getElementById('scriptSearch');
        const scriptsGrid = document.getElementById('scriptsGrid');

        const imageModal = document.getElementById('imageModal');
        const popupImage = document.getElementById('popupImage');
        const closeImageModalBtn = document.getElementById('closeImageModalBtn');
        const popupImageId = document.getElementById('popupImageId');
        const popupTitleInput = document.getElementById('popupTitleInput');
        const popupLinkInput = document.getElementById('popupLinkInput');
        const popupLinkPreview = document.getElementById('popupLinkPreview');
        const saveImageEditBtn = document.getElementById('saveImageEditBtn');
        const copyImageLinkBtn = document.getElementById('copyImageLinkBtn');
        const openImageLinkBtn = document.getElementById('openImageLinkBtn');
        const deleteImageFromPopupBtn = document.getElementById('deleteImageFromPopupBtn');

        const noteModal = document.getElementById('noteModal');
        const modalTitle = document.getElementById('modalTitle');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const noteId = document.getElementById('noteId');
        const noteTitleInput = document.getElementById('noteTitleInput');
        const noteTextInput = document.getElementById('noteTextInput');
        const saveNoteBtn = document.getElementById('saveNoteBtn');
        const resetNoteBtn = document.getElementById('resetNoteBtn');
        const colorRow = document.getElementById('colorRow');

        const scriptModal = document.getElementById('scriptModal');
        const scriptModalTitle = document.getElementById('scriptModalTitle');
        const closeScriptModalBtn = document.getElementById('closeScriptModalBtn');
        const scriptId = document.getElementById('scriptId');
        const scriptTitleInput = document.getElementById('scriptTitleInput');
        const scriptLanguageInput = document.getElementById('scriptLanguageInput');
        const scriptCodeInput = document.getElementById('scriptCodeInput');
        const scriptNoteInput = document.getElementById('scriptNoteInput');
        const scriptFavoriteInput = document.getElementById('scriptFavoriteInput');
        const saveScriptBtn = document.getElementById('saveScriptBtn');
        const resetScriptBtn = document.getElementById('resetScriptBtn');

        const toast = document.getElementById('toast');

        let isNewFavorite = false;
        let selectedNoteColor = '#fff1b8';
        let imageTimer = null;
        let noteTimer = null;
        let scriptTimer = null;
        let currentPopupItem = null;

        function showToast(message) {
            toast.textContent = message;
            toast.style.display = 'block';

            clearTimeout(showToast.timer);
            showToast.timer = setTimeout(() => {
                toast.style.display = 'none';
            }, 2800);
        }

        async function requestJson(url, options = {}) {
            const response = await fetch(url, options);
            const json = await response.json().catch(() => ({}));

            if (!response.ok || json.success === false) {
                throw new Error(json.message || 'Terjadi kesalahan.');
            }

            return json;
        }

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value ?? '';
            return div.innerHTML;
        }

        function escapeAttr(value) {
            return escapeHtml(value).replaceAll("'", '&#039;').replaceAll('"', '&quot;');
        }

        function absoluteLink(text) {
            if (!text) return '';

            if (text.startsWith('uploads/')) {
                return `${location.origin}${location.pathname.replace(/\/[^\/]*$/, '/')}${text}`;
            }

            return text;
        }

        async function copyRawText(text, message = 'Berhasil dicopy.') {
            try {
                await navigator.clipboard.writeText(text);
                showToast(message);
            } catch (error) {
                const temp = document.createElement('textarea');
                temp.value = text;
                temp.style.position = 'fixed';
                temp.style.left = '-9999px';
                document.body.appendChild(temp);
                temp.focus();
                temp.select();
                document.execCommand('copy');
                temp.remove();
                showToast(message);
            }
        }

        async function copyText(text) {
            await copyRawText(absoluteLink(text), 'Link berhasil dicopy.');
        }

        function copyEncodedText(encodedText) {
            copyRawText(decodeURIComponent(encodedText), 'Text berhasil dicopy.');
        }

        function imageError(img) {
            const wrap = img.closest('.image-preview') || img.closest('.popup-image-area');

            wrap.innerHTML = `
                <div class="image-fallback">
                    Foto tidak bisa tampil.<br>
                    Pastikan file masih ada di folder <b>uploads/</b>.
                </div>
            `;
        }

        function showGallery() {
            gallerySection.classList.add('active');
            notesSection.classList.remove('active');
            scriptsSection.classList.remove('active');

            galleryToolbar.style.display = 'flex';
            notesToolbar.style.display = 'none';
            scriptsToolbar.style.display = 'none';

            showGalleryBtn.classList.add('btn-menu-active');
            showNotesBtn.classList.remove('btn-menu-active');
            showScriptsBtn.classList.remove('btn-menu-active');

            loadImages();
        }

        function showNotes() {
            notesSection.classList.add('active');
            gallerySection.classList.remove('active');
            scriptsSection.classList.remove('active');

            galleryToolbar.style.display = 'none';
            notesToolbar.style.display = 'flex';
            scriptsToolbar.style.display = 'none';

            showNotesBtn.classList.add('btn-menu-active');
            showGalleryBtn.classList.remove('btn-menu-active');
            showScriptsBtn.classList.remove('btn-menu-active');

            loadNotes();
        }

        function showScripts() {
            scriptsSection.classList.add('active');
            gallerySection.classList.remove('active');
            notesSection.classList.remove('active');

            galleryToolbar.style.display = 'none';
            notesToolbar.style.display = 'none';
            scriptsToolbar.style.display = 'flex';

            showScriptsBtn.classList.add('btn-menu-active');
            showNotesBtn.classList.remove('btn-menu-active');
            showGalleryBtn.classList.remove('btn-menu-active');

            loadScripts();
        }

        async function loadImages() {
            const q = encodeURIComponent(imageSearch.value.trim());
            const fav = favoriteOnly.checked ? 1 : 0;
            const json = await requestJson(api('images') + `&q=${q}&favorite=${fav}`);
            const data = json.data || [];

            imageCount.textContent = `${data.length} gambar tersimpan`;

            if (data.length === 0) {
                galleryGrid.innerHTML = `<div class="clean-empty">Belum ada gambar tersimpan.</div>`;
                return;
            }

            galleryGrid.innerHTML = data.map(item => {
                const id = Number(item.id);
                const title = item.title || 'Tanpa Judul';
                const link = item.imgur_link || '';
                const pageLink = item.imgur_page_link || link;
                const favNext = Number(item.is_favorite) ? 0 : 1;

                const safeItem = encodeURIComponent(JSON.stringify({
                    id,
                    title,
                    imgur_link: link,
                    imgur_page_link: pageLink,
                    is_favorite: Number(item.is_favorite)
                }));

                return `
                    <article class="image-card">
                        <div class="card-menu">
                            <button class="btn star-button ${Number(item.is_favorite) ? '' : 'off'}"
                                type="button" onclick="toggleFavorite(${id}, ${favNext})">★</button>

                            <button class="btn btn-dark" type="button" onclick="copyText('${escapeAttr(link)}')">Copy</button>

                            <button class="btn btn-dark" type="button" onclick="openImagePopup('${safeItem}')">Edit</button>

                            <button class="btn btn-delete" type="button" onclick="deleteImage(${id})">×</button>
                        </div>

                        <div class="image-preview" style="--img-bg:url('${escapeAttr(link)}')" onclick="openImagePopup('${safeItem}')">
                            <img src="${escapeAttr(link)}" alt="${escapeAttr(title)}" loading="lazy" onerror="imageError(this)">
                        </div>

                        <div class="image-title-box">
                            <h3 class="image-title">${escapeHtml(title)}</h3>
                            <a class="image-link-small" href="${escapeAttr(pageLink)}" target="_blank">${escapeHtml(link)}</a>
                        </div>
                    </article>
                `;
            }).join('');
        }

        function openImagePopup(encodedItem) {
            const item = JSON.parse(decodeURIComponent(encodedItem));
            currentPopupItem = item;

            popupImageId.value = item.id;
            popupTitleInput.value = item.title || '';
            popupLinkInput.value = item.imgur_link || '';
            popupLinkPreview.href = item.imgur_page_link || item.imgur_link || '#';
            popupLinkPreview.textContent = item.imgur_link || '-';

            popupImage.src = item.imgur_link || '';
            popupImage.onerror = function () {
                this.closest('.popup-image-area').innerHTML = `
                    <div class="image-fallback">
                        Foto tidak bisa tampil.<br>
                        Cek kembali link gambarnya.
                    </div>
                `;
            };

            imageModal.classList.add('open');
        }

        function closeImagePopup() {
            imageModal.classList.remove('open');

            const area = imageModal.querySelector('.popup-image-area');
            if (!area.querySelector('img')) {
                area.innerHTML = `<img id="popupImage" src="" alt="Preview gambar">`;
                window.location.reload();
            }
        }

        async function toggleFavorite(id, favorite) {
            await requestJson(api('toggle_favorite'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id, is_favorite: favorite})
            });

            loadImages().catch(error => showToast(error.message));
        }

        async function deleteImage(id) {
            if (!confirm('Hapus gambar ini dari database dan folder uploads?')) return;

            await requestJson(api('delete_image'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id})
            });

            showToast('Gambar berhasil dihapus.');
            loadImages().catch(error => showToast(error.message));
        }

        imageForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (notesSection.classList.contains('active')) {
                return;
            }

            try {
                await requestJson(api('save_image'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        title: titleInput.value.trim(),
                        imgur_link: linkInput.value.trim(),
                        is_favorite: isNewFavorite ? 1 : 0
                    })
                });

                linkInput.value = '';
                titleInput.value = '';
                isNewFavorite = false;
                newFavorite.classList.add('off');

                showToast('Link gambar berhasil tersimpan ke database.');
                showGallery();
            } catch (error) {
                showToast(error.message);
            }
        });

        newFavorite.addEventListener('click', () => {
            isNewFavorite = !isNewFavorite;
            newFavorite.classList.toggle('off', !isNewFavorite);
        });

        showGalleryBtn.addEventListener('click', () => {
            showGallery();
        });

        backGalleryFromNotesBtn.addEventListener('click', () => {
            showGallery();
        });

        uploadBtn.addEventListener('click', () => {
            showGallery();
            fileInput.click();
        });

        fileInput.addEventListener('change', async () => {
            if (!fileInput.files.length) return;

            const formData = new FormData();
            formData.append('image', fileInput.files[0]);
            formData.append('title', titleInput.value.trim());
            formData.append('is_favorite', isNewFavorite ? '1' : '0');

            try {
                showToast('Sedang upload gambar ke folder lokal...');

                const json = await requestJson(api('upload_local'), {
                    method: 'POST',
                    body: formData
                });

                linkInput.value = json.data.link;
                fileInput.value = '';

                showToast('Upload berhasil dan sudah tersimpan ke database.');
                showGallery();
            } catch (error) {
                showToast(error.message);
            }
        });

        imageSearch.addEventListener('input', () => {
            clearTimeout(imageTimer);
            imageTimer = setTimeout(() => {
                loadImages().catch(error => showToast(error.message));
            }, 250);
        });

        favoriteOnly.addEventListener('change', () => {
            loadImages().catch(error => showToast(error.message));
        });

        closeImageModalBtn.addEventListener('click', () => {
            closeImagePopup();
        });

        imageModal.addEventListener('click', (event) => {
            if (event.target === imageModal) {
                closeImagePopup();
            }
        });

        saveImageEditBtn.addEventListener('click', async () => {
            try {
                const id = Number(popupImageId.value);
                const title = popupTitleInput.value.trim();
                const link = popupLinkInput.value.trim();

                await requestJson(api('edit_image'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        id,
                        title,
                        imgur_link: link
                    })
                });

                popupImage.src = link;
                popupLinkPreview.href = link;
                popupLinkPreview.textContent = link;

                showToast('Judul dan link berhasil diedit.');
                loadImages();
            } catch (error) {
                showToast(error.message);
            }
        });

        copyImageLinkBtn.addEventListener('click', () => {
            copyText(popupLinkInput.value.trim());
        });

        openImageLinkBtn.addEventListener('click', () => {
            const link = absoluteLink(popupLinkInput.value.trim());
            if (link) {
                window.open(link, '_blank');
            }
        });

        deleteImageFromPopupBtn.addEventListener('click', async () => {
            const id = Number(popupImageId.value);
            if (!id) return;

            if (!confirm('Hapus gambar ini?')) return;

            try {
                await requestJson(api('delete_image'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });

                closeImagePopup();
                showToast('Gambar berhasil dihapus.');
                loadImages();
            } catch (error) {
                showToast(error.message);
            }
        });

        popupLinkInput.addEventListener('input', () => {
            popupLinkPreview.href = popupLinkInput.value.trim() || '#';
            popupLinkPreview.textContent = popupLinkInput.value.trim() || '-';
        });


        /* =========================
           SCRIPT LIBRARY
        ========================= */
        function openScriptModal(script = null) {
            scriptModal.classList.add('open');

            if (script) {
                scriptModalTitle.textContent = 'Edit Script';
                scriptId.value = script.id;
                scriptTitleInput.value = script.script_title || '';
                scriptLanguageInput.value = script.script_language || 'text';
                scriptCodeInput.value = script.script_code || '';
                scriptNoteInput.value = script.script_note || '';
                scriptFavoriteInput.checked = Number(script.is_favorite) === 1;
            } else {
                scriptModalTitle.textContent = 'Tambah Script';
                scriptId.value = '';
                scriptTitleInput.value = '';
                scriptLanguageInput.value = '';
                scriptCodeInput.value = '';
                scriptNoteInput.value = '';
                scriptFavoriteInput.checked = false;
            }

            scriptTitleInput.focus();
        }

        function closeScriptModal() {
            scriptModal.classList.remove('open');
        }

        async function loadScripts() {
            const q = encodeURIComponent(scriptSearch.value.trim());
            const json = await requestJson(api('scripts') + `&q=${q}`);
            const data = json.data || [];

            if (data.length === 0) {
                scriptsGrid.innerHTML = `<div class="clean-empty">Belum ada script tersimpan.</div>`;
                return;
            }

            scriptsGrid.innerHTML = data.map(script => {
                const id = Number(script.id);
                const title = script.script_title || 'Tanpa Judul';
                const language = script.script_language || 'text';
                const scriptCode = script.script_code || '';
                const note = script.script_note || '';
                const favorite = Number(script.is_favorite) === 1;

                const safeScript = encodeURIComponent(JSON.stringify({
                    id,
                    script_title: title,
                    script_language: language,
                    script_code: scriptCode,
                    script_note: note,
                    is_favorite: favorite ? 1 : 0
                }));
                const safeCode = encodeURIComponent(scriptCode);

                const preview = scriptCode.length > 2200 ? scriptCode.slice(0, 2200) + '\\n...' : scriptCode;

                return `
                    <article class="script-card">
                        <div class="script-card-head">
                            <div>
                                <h3 class="script-title">${escapeHtml(title)}</h3>
                                <div class="script-meta">
                                    <span class="language-pill">${escapeHtml(language)}</span>
                                    <span>${favorite ? '★ Favorit' : 'Saved ✓'}</span>
                                </div>
                            </div>
                            <button class="btn star-button ${favorite ? '' : 'off'}"
                                type="button" onclick="toggleScriptFavorite(${id}, ${favorite ? 0 : 1})">★</button>
                        </div>

                        <pre class="script-code-preview">${escapeHtml(preview)}</pre>
                        ${note ? `<p class="script-note">${escapeHtml(note)}</p>` : ''}

                        <div class="script-actions">
                            <button class="btn btn-dark" type="button" onclick="copyEncodedText('${safeCode}')">Copy</button>
                            <button class="btn btn-dark" type="button" onclick="editScript('${safeScript}')">Edit</button>
                            <button class="btn btn-delete" type="button" onclick="deleteScript(${id})">×</button>
                        </div>
                    </article>
                `;
            }).join('');
        }

        window.editScript = function(encodedScript) {
            const script = JSON.parse(decodeURIComponent(encodedScript));
            openScriptModal(script);
        };

        window.deleteScript = async function(id) {
            if (!confirm('Hapus script ini?')) return;

            try {
                await requestJson(api('delete_script'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });

                showToast('Script berhasil dihapus.');
                loadScripts();
            } catch (error) {
                showToast(error.message);
            }
        };

        async function toggleScriptFavorite(id, favorite) {
            await requestJson(api('toggle_script_favorite'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id, is_favorite: favorite})
            });

            loadScripts().catch(error => showToast(error.message));
        }

        showScriptsBtn.addEventListener('click', () => {
            showScripts();
        });

        showScriptsFromNotesBtn.addEventListener('click', () => {
            showScripts();
        });

        showNotesFromScriptsBtn.addEventListener('click', () => {
            showNotes();
        });

        backGalleryFromScriptsBtn.addEventListener('click', () => {
            showGallery();
        });

        addScriptBtn.addEventListener('click', () => {
            openScriptModal();
        });

        closeScriptModalBtn.addEventListener('click', closeScriptModal);

        scriptModal.addEventListener('click', (event) => {
            if (event.target === scriptModal) {
                closeScriptModal();
            }
        });

        resetScriptBtn.addEventListener('click', () => {
            scriptId.value = '';
            scriptTitleInput.value = '';
            scriptLanguageInput.value = '';
            scriptCodeInput.value = '';
            scriptNoteInput.value = '';
            scriptFavoriteInput.checked = false;
        });

        saveScriptBtn.addEventListener('click', async () => {
            try {
                await requestJson(api('save_script'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        id: scriptId.value ? Number(scriptId.value) : 0,
                        script_title: scriptTitleInput.value.trim(),
                        script_language: scriptLanguageInput.value.trim() || 'text',
                        script_code: scriptCodeInput.value,
                        script_note: scriptNoteInput.value.trim(),
                        is_favorite: scriptFavoriteInput.checked ? 1 : 0
                    })
                });

                closeScriptModal();
                showToast('Script berhasil disimpan.');
                showScripts();
            } catch (error) {
                showToast(error.message);
            }
        });

        scriptSearch.addEventListener('input', () => {
            clearTimeout(scriptTimer);
            scriptTimer = setTimeout(() => {
                loadScripts().catch(error => showToast(error.message));
            }, 250);
        });

        /* =========================
           STICKY NOTES
        ========================= */
        function openNoteModal(note = null) {
            noteModal.classList.add('open');

            if (note) {
                modalTitle.textContent = 'Edit Sticky Note';
                noteId.value = note.id;
                noteTitleInput.value = note.note_title || '';
                noteTextInput.value = note.note_text || '';
                selectedNoteColor = note.note_color || '#fff1b8';
            } else {
                modalTitle.textContent = 'Tambah Sticky Note';
                noteId.value = '';
                noteTitleInput.value = '';
                noteTextInput.value = '';
                selectedNoteColor = '#fff1b8';
            }

            syncColorDots();
            noteTitleInput.focus();
        }

        function closeNoteModal() {
            noteModal.classList.remove('open');
        }

        function syncColorDots() {
            document.querySelectorAll('.color-dot').forEach(dot => {
                dot.classList.toggle('active', dot.dataset.color === selectedNoteColor);
            });
        }

        async function loadNotes() {
            const q = encodeURIComponent(noteSearch.value.trim());
            const json = await requestJson(api('notes') + `&q=${q}`);
            const data = json.data || [];

            if (data.length === 0) {
                notesGrid.innerHTML = `<div class="clean-empty">Belum ada sticky note.</div>`;
                return;
            }

            notesGrid.innerHTML = data.map(note => {
                const id = Number(note.id);
                const title = note.note_title || 'Tanpa Judul';
                const text = note.note_text || '';
                const color = note.note_color || '#fff1b8';

                const safeNote = encodeURIComponent(JSON.stringify({
                    id,
                    note_title: title,
                    note_text: text,
                    note_color: color
                }));
                const safeText = encodeURIComponent(text);

                return `
                    <article class="note-card" style="background:${escapeAttr(color)}">
                        <div class="note-inner">
                            <h3 class="note-title">${escapeHtml(title)}</h3>
                            <div class="note-line"></div>
                            <p class="note-text">${escapeHtml(text)}</p>

                            <div class="note-foot">
                                <span class="saved">Saved ✓</span>

                                <div class="note-actions">
                                    <button class="icon-btn" type="button" title="Copy"
                                        onclick="copyEncodedText('${safeText}')">📋</button>
                                    <button class="icon-btn" type="button" title="Edit"
                                        onclick="editNote('${safeNote}')">✎</button>
                                    <button class="icon-btn" type="button" title="Hapus"
                                        onclick="deleteNote(${id})">🗑</button>
                                </div>
                            </div>
                        </div>
                    </article>
                `;
            }).join('');
        }

        window.editNote = function(encodedNote) {
            const note = JSON.parse(decodeURIComponent(encodedNote));
            openNoteModal(note);
        };

        window.deleteNote = async function(id) {
            if (!confirm('Hapus sticky note ini?')) return;

            try {
                await requestJson(api('delete_note'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });

                showToast('Sticky note berhasil dihapus.');
                loadNotes();
            } catch (error) {
                showToast(error.message);
            }
        };

        showNotesBtn.addEventListener('click', () => {
            showNotes();
        });

        addNoteBtn.addEventListener('click', () => {
            openNoteModal();
        });

        closeModalBtn.addEventListener('click', closeNoteModal);

        noteModal.addEventListener('click', (event) => {
            if (event.target === noteModal) {
                closeNoteModal();
            }
        });

        resetNoteBtn.addEventListener('click', () => {
            noteId.value = '';
            noteTitleInput.value = '';
            noteTextInput.value = '';
            selectedNoteColor = '#fff1b8';
            syncColorDots();
        });

        colorRow.addEventListener('click', (event) => {
            const dot = event.target.closest('.color-dot');
            if (!dot) return;

            selectedNoteColor = dot.dataset.color;
            syncColorDots();
        });

        saveNoteBtn.addEventListener('click', async () => {
            try {
                await requestJson(api('save_note'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        id: noteId.value ? Number(noteId.value) : 0,
                        note_title: noteTitleInput.value.trim(),
                        note_text: noteTextInput.value.trim(),
                        note_color: selectedNoteColor
                    })
                });

                closeNoteModal();
                showToast('Sticky note berhasil disimpan.');
                showNotes();
            } catch (error) {
                showToast(error.message);
            }
        });

        noteSearch.addEventListener('input', () => {
            clearTimeout(noteTimer);
            noteTimer = setTimeout(() => {
                loadNotes().catch(error => showToast(error.message));
            }, 250);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeImagePopup();
                closeNoteModal();
                closeScriptModal();
            }
        });

        showGallery();
    </script>
</body>
</html>
