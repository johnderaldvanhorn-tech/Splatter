<?php
declare(strict_types=1);

const VERSION = '1.8.2';
const MAX_JSON_BODY = 15728640; // 15 MB
const MAX_IMAGE_BYTES = 8388608; // 8 MB server-side hard limit

$ROOT = dirname(__DIR__);
$DATA = $ROOT . '/data';
$BACKUPS = $ROOT . '/backups';
$UPLOADS = $ROOT . '/uploads';
$PROJECTS_FILE = $DATA . '/projects.json';
$BIO_FILE = $DATA . '/bio.json';
$SETTINGS_FILE = $DATA . '/settings.json';
$USERS_FILE = $DATA . '/users.json';

foreach ([$DATA, $BACKUPS, $UPLOADS, "$UPLOADS/projects", "$UPLOADS/bio"] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

// First-run local admin bootstrap. Change this password immediately after deployment.
if (!is_file($USERS_FILE)) {
    $initialUsers = [[
        'username' => 'admin',
        'passwordHash' => password_hash('splatter', PASSWORD_DEFAULT),
        'mustChangePassword' => true
    ]];
    @file_put_contents($USERS_FILE, json_encode($initialUsers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_name('splatter_session');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function send_json(int $status, mixed $data, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $name => $value) header($name . ': ' . $value);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_file(string $file, mixed $default): mixed {
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false) return $default;
    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function stamp(): string {
    return gmdate('Y-m-d\TH-i-s') . '-' . substr((string)microtime(true), -3);
}

function atomic_write_json(string $file, mixed $value): void {
    global $BACKUPS;
    if (is_file($file)) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        @copy($file, $BACKUPS . '/' . $name . '-' . stamp() . '.json');
    }
    $tmp = $file . '.' . getmypid() . '.tmp';
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || @file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write local JSON data.');
    }
    $verify = json_decode((string)file_get_contents($tmp), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        @unlink($tmp);
        throw new RuntimeException('JSON validation failed before save.');
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to activate saved JSON data.');
    }
}

function request_body(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > MAX_JSON_BODY) send_json(413, ['error' => 'Request too large.']);
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > MAX_JSON_BODY) send_json(413, ['error' => 'Request too large.']);
    $data = json_decode($raw, true);
    if (!is_array($data)) send_json(400, ['error' => 'Invalid JSON.']);
    return $data;
}

function authenticated_user(): ?array {
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_auth(): array {
    $u = authenticated_user();
    if (!$u) send_json(401, ['error' => 'Authentication required.']);
    return $u;
}

function slugify(string $value): string {
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function project_clean(array $x, int $i): array {
    $title = (string)($x['title'] ?? 'Untitled Project');
    $status = (string)($x['status'] ?? 'concept');
    $category = (string)($x['category'] ?? 'hardware');
    return [
        'id' => (string)($x['id'] ?? ('p-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT))),
        'slug' => slugify((string)($x['slug'] ?? $title ?: ('project-' . ($i + 1)))),
        'title' => $title,
        'category' => $category,
        'status' => $status,
        'accent' => (string)($x['accent'] ?? ($category === 'software' ? 'cyan' : ($category === 'systems' ? 'yellow' : 'magenta'))),
        'shortDescription' => (string)($x['shortDescription'] ?? ''),
        'description' => (string)($x['description'] ?? ''),
        'startedAt' => (string)($x['startedAt'] ?? gmdate('Y-m-d')),
        'updatedAt' => gmdate('c'),
        'phase' => (string)($x['phase'] ?? $status),
        'tags' => isset($x['tags']) && is_array($x['tags']) ? array_values(array_map('strval', $x['tags'])) : [],
        'heroImage' => (string)($x['heroImage'] ?? '/assets/edge-node.webp'),
        'gallery' => isset($x['gallery']) && is_array($x['gallery']) ? array_values($x['gallery']) : [],
        'youtubeUrl' => (string)($x['youtubeUrl'] ?? ''),
        'featured' => !empty($x['featured']),
        'published' => !array_key_exists('published', $x) || $x['published'] !== false,
        'displayOrder' => (int)($x['displayOrder'] ?? ($i + 1)),
    ];
}

function save_data_url(string $dataUrl, string $kind): string {
    global $UPLOADS;
    if (!preg_match('#^data:(image/(?:png|jpeg|webp|gif));base64,(.+)$#s', $dataUrl, $m)) {
        throw new RuntimeException('Unsupported image format.');
    }
    $binary = base64_decode($m[2], true);
    if ($binary === false) throw new RuntimeException('Invalid image data.');
    if (strlen($binary) > MAX_IMAGE_BYTES) throw new RuntimeException('Image exceeds 10 MB.');
    $ext = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'][$m[1]] ?? null;
    if (!$ext) throw new RuntimeException('Unsupported image format.');
    $dir = $kind === 'bio' ? 'bio' : 'projects';
    $name = time() . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    $path = $UPLOADS . '/' . $dir . '/' . $name;
    if (@file_put_contents($path, $binary, LOCK_EX) === false) throw new RuntimeException('Unable to save uploaded image.');
    return '/uploads/' . $dir . '/' . $name;
}

function save_multipart_upload(array $file, string $kind): string {
    global $UPLOADS;
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Image exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'Image exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'Image upload was interrupted.',
            UPLOAD_ERR_NO_FILE => 'No image was received.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded image.',
            UPLOAD_ERR_EXTENSION => 'Server rejected the uploaded image.'
        ];
        throw new RuntimeException($messages[$error] ?? 'Image upload failed.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) throw new RuntimeException('Uploaded image is empty.');
    if ($size > MAX_IMAGE_BYTES) throw new RuntimeException('Image exceeds 8 MB.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid uploaded file.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $exts = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($exts[$mime])) throw new RuntimeException('Unsupported image format. Use PNG, JPG, WebP or GIF.');

    $dir = $kind === 'bio' ? 'bio' : 'projects';
    $name = time() . '-' . bin2hex(random_bytes(5)) . '.' . $exts[$mime];
    $destination = $UPLOADS . '/' . $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('Unable to save uploaded image.');
    @chmod($destination, 0644);
    return '/uploads/' . $dir . '/' . $name;
}

function parse_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $last = strtolower($value[strlen($value)-1]);
    $num = (float)$value;
    return match ($last) {
        'g' => (int)($num * 1024 * 1024 * 1024),
        'm' => (int)($num * 1024 * 1024),
        'k' => (int)($num * 1024),
        default => (int)$num,
    };
}

function settings_default(): array {
    return ['siteName'=>'Splatter Innovations','tagline'=>'Splatter. Storm and Bake.','established'=>'2024','storageMode'=>'local-json-php'];
}

$route = trim((string)($_GET['route'] ?? ''), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($route === 'health' && $method === 'GET') {
        send_json(200, ['ok'=>true,'version'=>VERSION,'runtime'=>'php','time'=>gmdate('c')]);
    }

    if ($route === 'meta' && $method === 'GET') {
        send_json(200, [
            'name'=>'Splatter Innovations API',
            'version'=>VERSION,
            'runtime'=>'php',
            'endpoints'=>['health','meta','projects','bio','settings','auth/session','auth/login']
        ]);
    }

    if ($route === 'admin/system' && $method === 'GET') {
        require_auth();
        send_json(200, [
            'version'=>VERSION,
            'runtime'=>'php',
            'phpVersion'=>PHP_VERSION,
            'uploadMaxFilesize'=>ini_get('upload_max_filesize'),
            'postMaxSize'=>ini_get('post_max_size'),
            'uploadMaxBytes'=>parse_ini_bytes((string)ini_get('upload_max_filesize')),
            'postMaxBytes'=>parse_ini_bytes((string)ini_get('post_max_size')),
            'dataWritable'=>is_writable($DATA),
            'backupsWritable'=>is_writable($BACKUPS),
            'uploadsWritable'=>is_writable($UPLOADS),
            'projectsWritable'=>is_writable($PROJECTS_FILE) || is_writable($DATA),
            'bioWritable'=>is_writable($BIO_FILE) || is_writable($DATA),
        ]);
    }

    if ($route === 'projects' && $method === 'GET') {
        $projects = read_json_file($PROJECTS_FILE, []);
        if (!is_array($projects)) $projects = [];
        if (!authenticated_user()) $projects = array_values(array_filter($projects, fn($p) => !isset($p['published']) || $p['published'] !== false));
        usort($projects, fn($a,$b) => ((int)($a['displayOrder'] ?? 999)) <=> ((int)($b['displayOrder'] ?? 999)));
        send_json(200, $projects);
    }

    if ($route === 'bio' && $method === 'GET') send_json(200, read_json_file($BIO_FILE, []));
    if ($route === 'settings' && $method === 'GET') send_json(200, array_merge(settings_default(), read_json_file($SETTINGS_FILE, [])));

    if ($route === 'settings' && $method === 'PUT') {
        require_auth();
        $next = array_merge(settings_default(), read_json_file($SETTINGS_FILE, []), request_body(), ['storageMode'=>'local-json-php']);
        atomic_write_json($SETTINGS_FILE, $next);
        send_json(200, $next);
    }

    if ($route === 'auth/session' && $method === 'GET') {
        $u = authenticated_user();
        send_json(200, ['authenticated'=>(bool)$u, 'user'=>$u]);
    }

    if ($route === 'auth/login' && $method === 'POST') {
        $b = request_body();
        $username = (string)($b['username'] ?? '');
        $password = (string)($b['password'] ?? '');
        $users = read_json_file($USERS_FILE, []);
        $found = null;
        foreach ((array)$users as $u) if (($u['username'] ?? '') === $username) { $found = $u; break; }
        if (!$found || !password_verify($password, (string)($found['passwordHash'] ?? ''))) {
            send_json(401, ['error'=>'Invalid username or password.']);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['username'=>$username];
        send_json(200, ['ok'=>true,'user'=>['username'=>$username]]);
    }

    if ($route === 'auth/logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        send_json(200, ['ok'=>true]);
    }

    if ($route === 'auth/change-password' && $method === 'POST') {
        $current = require_auth();
        $b = request_body();
        $password = (string)($b['password'] ?? '');
        if (strlen($password) < 8) send_json(400, ['error'=>'Password must be at least 8 characters.']);
        $users = read_json_file($USERS_FILE, []);
        foreach ($users as &$u) if (($u['username'] ?? '') === ($current['username'] ?? '')) $u['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
        unset($u);
        atomic_write_json($USERS_FILE, $users);
        send_json(200, ['ok'=>true]);
    }

    if ($route === 'admin/export/projects' && $method === 'GET') {
        require_auth();
        $payload = [
            'exportVersion'=>1,
            'exportedAt'=>gmdate('c'),
            'site'=>'Splatter Innovations',
            'projects'=>read_json_file($PROJECTS_FILE, [])
        ];
        $filename = 'splatter-projects-' . gmdate('Y-m-d') . '.json';
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($route === 'admin/import/projects' && $method === 'POST') {
        require_auth();
        $b = request_body();
        $incoming = array_is_list($b) ? $b : ($b['projects'] ?? null);
        if (!is_array($incoming)) send_json(400, ['error'=>'Import file must contain a projects array.']);
        $clean = [];
        foreach ($incoming as $i => $x) if (is_array($x)) $clean[] = project_clean($x, (int)$i);
        atomic_write_json($PROJECTS_FILE, $clean);
        send_json(200, ['ok'=>true,'count'=>count($clean)]);
    }

    if ($route === 'admin/uploads' && $method === 'POST') {
        require_auth();
        $kind = (string)($_GET['kind'] ?? 'projects');
        if (isset($_FILES['file']) && is_array($_FILES['file'])) {
            send_json(201, ['url'=>save_multipart_upload($_FILES['file'], $kind), 'mode'=>'multipart']);
        }
        // Backward-compatible JSON/data URL upload for older clients.
        $b = request_body();
        send_json(201, ['url'=>save_data_url((string)($b['dataUrl'] ?? ''), $kind), 'mode'=>'data-url']);
    }

    if ($route === 'admin/projects' && $method === 'POST') {
        require_auth();
        $b = request_body();
        $a = read_json_file($PROJECTS_FILE, []);
        if (!is_array($a)) $a = [];
        $max = 0;
        foreach ($a as $x) $max = max($max, (int)preg_replace('/\D+/', '', (string)($x['id'] ?? '0')));
        $title = (string)($b['title'] ?? 'Untitled Project');
        $category = (string)($b['category'] ?? 'hardware');
        $status = (string)($b['status'] ?? 'concept');
        $item = [
            'id'=>(string)($b['id'] ?? ('p-' . str_pad((string)($max+1), 2, '0', STR_PAD_LEFT))),
            'slug'=>slugify((string)($b['slug'] ?? $title)),
            'title'=>$title,
            'category'=>$category,
            'status'=>$status,
            'accent'=>(string)($b['accent'] ?? ($category === 'software' ? 'cyan' : ($category === 'systems' ? 'yellow' : 'magenta'))),
            'shortDescription'=>(string)($b['shortDescription'] ?? ''),
            'description'=>(string)($b['description'] ?? ''),
            'startedAt'=>(string)($b['startedAt'] ?? gmdate('Y-m-d')),
            'updatedAt'=>gmdate('c'),
            'phase'=>(string)($b['phase'] ?? $status),
            'tags'=>isset($b['tags']) && is_array($b['tags']) ? array_values(array_map('strval', $b['tags'])) : [],
            'heroImage'=>(string)($b['heroImage'] ?? '/assets/edge-node.webp'),
            'gallery'=>isset($b['gallery']) && is_array($b['gallery']) ? array_values($b['gallery']) : [],
            'youtubeUrl'=>(string)($b['youtubeUrl'] ?? ''),
            'featured'=>!empty($b['featured']),
            'published'=>!array_key_exists('published', $b) || $b['published'] !== false,
            'displayOrder'=>(int)($b['displayOrder'] ?? (count($a)+1)),
        ];
        $a[] = $item;
        atomic_write_json($PROJECTS_FILE, $a);
        send_json(201, $item);
    }

    if (preg_match('#^admin/projects/([^/]+)$#', $route, $m)) {
        require_auth();
        $id = rawurldecode($m[1]);
        $a = read_json_file($PROJECTS_FILE, []);
        if (!is_array($a)) $a = [];
        $index = null;
        foreach ($a as $i => $p) if (($p['id'] ?? '') === $id) { $index = $i; break; }
        if ($index === null) send_json(404, ['error'=>'Project not found.']);
        if ($method === 'PUT') {
            $a[$index] = array_merge($a[$index], request_body(), ['updatedAt'=>gmdate('c')]);
            atomic_write_json($PROJECTS_FILE, $a);
            send_json(200, $a[$index]);
        }
        if ($method === 'DELETE') {
            array_splice($a, $index, 1);
            atomic_write_json($PROJECTS_FILE, $a);
            send_json(200, ['ok'=>true]);
        }
    }

    if ($route === 'admin/bio' && $method === 'PUT') {
        require_auth();
        $next = array_merge(read_json_file($BIO_FILE, []), request_body());
        atomic_write_json($BIO_FILE, $next);
        send_json(200, $next);
    }

    send_json(404, ['error'=>'Not found.']);
} catch (Throwable $e) {
    send_json(500, ['error'=>$e->getMessage() ?: 'Server error.']);
}
