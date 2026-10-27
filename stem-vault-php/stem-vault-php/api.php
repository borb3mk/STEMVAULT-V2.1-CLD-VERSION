<?php
declare(strict_types=1);

/**
 * STEM Vault — Backend API (PHP 7.4+ / SQLite)
 */

$cfg = require __DIR__ . '/config.php';
$cfg += ['storage_dir' => __DIR__ . '/storage', 'max_upload_mb' => 50, 'otp_ttl' => 600, 'debug_otp' => false, 'emailjs' => [], 'mail_from' => 'no-reply@localhost'];

const SUBJECTS = ['Biology', 'Geology', 'English', 'Mechanics', 'Math'];
const INLINE_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');

/* ---------- ردود JSON ---------- */
function out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    out(['ok' => false, 'error' => $msg], $code);
}

set_exception_handler(function (Throwable $e) {
    error_log('[stem-vault] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('حصل خطأ داخلي في السيرفر.', 500);
});

/* ---------- الجلسة ---------- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('stemvault');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 7,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- أدوات ---------- */
function ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0';
}

function is_owner(string $email): bool
{
    global $cfg;
    return strtolower($email) === strtolower((string)$cfg['owner_email']);
}

function current_user(): ?array
{
    $e = $_SESSION['user'] ?? null;
    return $e ? ['email' => $e, 'isOwner' => is_owner($e)] : null;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        fail('سجّل الدخول أولاً.', 401);
    }
    return $u;
}

function need_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail('طريقة الطلب غير صحيحة.', 405);
    }
}

function csrf_check(): void
{
    $t = (string)($_SESSION['csrf'] ?? '');
    $h = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($t === '' || !hash_equals($t, $h)) {
        fail('انتهت صلاحية الجلسة، حدّث الصفحة وحاول مرة أخرى.', 403);
    }
}

function body(): array
{
    static $b = null;
    if ($b === null) {
        $j = json_decode((string)file_get_contents('php://input'), true);
        $b = is_array($j) ? $j : [];
    }
    return $b;
}

function ini_bytes(string $key): int
{
    $v = trim((string)ini_get($key));
    if ($v === '' || $v === '0' || $v === '-1') {
        return PHP_INT_MAX;
    }
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024; // no break
        case 'm': $n *= 1024; // no break
        case 'k': $n *= 1024;
    }
    return $n;
}

function max_bytes(): int
{
    global $cfg;
    return (int)min($cfg['max_upload_mb'] * 1048576, ini_bytes('upload_max_filesize'), ini_bytes('post_max_size'));
}

function fmt_bytes(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . 'MB' : round($b / 1024) . 'KB';
}

/* ---------- قاعدة البيانات ---------- */
function storage(): string
{
    global $cfg;
    return rtrim((string)$cfg['storage_dir'], '/\\');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dir = storage();
    if (!is_dir($dir . '/uploads')) {
        @mkdir($dir . '/uploads', 0750, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $pdo = new PDO('sqlite:' . $dir . '/stem.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        pass_hash TEXT NOT NULL,
        created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        stored TEXT NOT NULL,
        type TEXT NOT NULL,
        size INTEGER NOT NULL,
        subject TEXT NOT NULL,
        uploader TEXT NOT NULL,
        created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS hits (k TEXT NOT NULL, t INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS hits_k ON hits(k, t)');
    return $pdo;
}

function rate_limit(string $key, int $max, int $window): bool
{
    $now = time();
    db()->prepare('DELETE FROM hits WHERE t < ?')->execute([$now - 3600]);
    $st = db()->prepare('SELECT COUNT(*) FROM hits WHERE k = ? AND t > ?');
    $st->execute([$key, $now - $window]);
    if ((int)$st->fetchColumn() >= $max) {
        return false;
    }
    db()->prepare('INSERT INTO hits (k, t) VALUES (?, ?)')->execute([$key, $now]);
    return true;
}

/* ---------- إرسال كود التحقق ---------- */
function http_post_json(string $url, array $payload): int
{
    $json = json_encode($payload);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200) {
            error_log('[stem-vault] EmailJS: ' . $status . ' ' . substr((string)$res, 0, 200));
        }
        curl_close($ch);
        return $status;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => $json, 'timeout' => 15, 'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
    if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        return (int)$m[1];
    }
    return 0;
}

function send_otp(string $email, string $code): bool
{
    global $cfg;
    $em = $cfg['emailjs'];
    if (!empty($em['private_key'])) {
        $status = http_post_json('https://api.emailjs.com/api/v1.0/email/send', [
            'service_id'      => $em['service_id'] ?? '',
            'template_id'     => $em['template_id'] ?? '',
            'user_id'         => $em['public_key'] ?? '',
            'accessToken'     => $em['private_key'],
            'template_params' => ['to_email' => $email, 'otp_code' => $code],
        ]);
        return $status === 200;
    }
    $subject = '=?UTF-8?B?' . base64_encode('كود التحقق - STEM Vault') . '?=';
    $msg = "كود التحقق الخاص بك في STEM Vault هو: {$code}\nالكود صالح لمدة " . intdiv((int)$cfg['otp_ttl'], 60) . " دقائق.\nلو ماطلبتش الكود تجاهل الرسالة.";
    $headers = "From: STEM Vault <{$cfg['mail_from']}>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($email, $subject, $msg, $headers);
}

/* ---------- التوجيه ---------- */
$action = (string)($_GET['action'] ?? '');

switch ($action) {

    case 'init':
        out([
            'ok'       => true,
            'csrf'     => $_SESSION['csrf'],
            'user'     => current_user(),
            'maxBytes' => max_bytes(),
        ]);
        break;

    case 'auth_start':
        need_post();
        csrf_check();
        $b = body();
        $mode  = (($b['mode'] ?? '') === 'login') ? 'login' : 'register';
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $pass  = (string)($b['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            fail('البريد الإلكتروني غير صالح.');
        }
        if (strlen($pass) < 6 || strlen($pass) > 200) {
            fail('كلمة المرور لازم تكون 6 حروف على الأقل.');
        }
        $p = $_SESSION['pending'] ?? null;
        if ($p && $p['email'] === $email && time() - $p['sent'] < 30) {
            fail('استنى 30 ثانية قبل طلب كود جديد.', 429);
        }
        if (!rate_limit('ip:' . ip(), 20, 600) || !rate_limit('em:' . $email, 6, 600)) {
            fail('محاولات كتير، استنى شوية وحاول تاني.', 429);
        }
        $st = db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $hash = null;
        if ($mode === 'register') {
            if ($u) {
                fail('هذا الحساب مسجل بالفعل! انتقل لتسجيل الدخول.');
            }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
        } else {
            if (!$u) {
                fail('هذا الحساب غير مسجل! اختر إنشاء حساب جديد أولاً.');
            }
            if (!password_verify($pass, $u['pass_hash'])) {
                fail('كلمة المرور غير صحيحة!');
            }
        }
        $code = (string)random_int(100000, 999999);
        $_SESSION['pending'] = [
            'email' => $email, 'mode' => $mode, 'hash' => $hash,
            'code' => $code, 'exp' => time() + (int)$cfg['otp_ttl'], 'sent' => time(), 'tries' => 0,
        ];
        $resp = ['ok' => true];
        $local = in_array(ip(), ['127.0.0.1', '::1'], true);
        if (!empty($cfg['debug_otp']) && $local) {
            $resp['debugCode'] = $code;
        } elseif (!send_otp($email, $code)) {
            unset($_SESSION['pending']);
            fail('تعذر إرسال كود التحقق. راجع إعدادات الإيميل في config.php.', 502);
        }
        out($resp);
        break;

    case 'auth_verify':
        need_post();
        csrf_check();
        $p = $_SESSION['pending'] ?? null;
        if (!$p) {
            fail('اطلب كود جديد أولاً.');
        }
        if (time() > $p['exp']) {
            unset($_SESSION['pending']);
            fail('انتهت صلاحية الكود، اطلب كود جديد.');
        }
        $code = preg_replace('/\D/', '', (string)(body()['code'] ?? ''));
        if (!hash_equals((string)$p['code'], (string)$code)) {
            $_SESSION['pending']['tries']++;
            if ($_SESSION['pending']['tries'] >= 5) {
                unset($_SESSION['pending']);
                fail('محاولات خاطئة كتير، اطلب كود جديد.', 429);
            }
            fail('كود التحقق غير صحيح! يرجى إعادة المحاولة.');
        }
        if ($p['mode'] === 'register') {
            db()->prepare('INSERT OR IGNORE INTO users (email, pass_hash, created_at) VALUES (?, ?, ?)')
                ->execute([$p['email'], $p['hash'], time()]);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = $p['email'];
        unset($_SESSION['pending']);
        out(['ok' => true, 'user' => current_user()]);
        break;

    case 'logout':
        need_post();
        csrf_check();
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);
        break;

    case 'list':
        $u = require_user();
        $rows = db()->query('SELECT id, name, type, size, subject, uploader, created_at FROM files ORDER BY id DESC')
            ->fetchAll(PDO::FETCH_ASSOC);
        $files = array_map(function ($r) use ($u) {
            return [
                'id'        => (int)$r['id'],
                'name'      => $r['name'],
                'type'      => $r['type'],
                'size'      => (int)$r['size'],
                'subject'   => $r['subject'],
                'uploader'  => $r['uploader'],
                'created'   => (int)$r['created_at'],
                'inline'    => in_array($r['type'], INLINE_TYPES, true),
                'canDelete' => $u['isOwner'] || $r['uploader'] === $u['email'],
            ];
        }, $rows);
        $stats = null;
        if ($u['isOwner']) {
            $stats = [
                'users' => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'files' => count($files),
            ];
        }
        out(['ok' => true, 'files' => $files, 'stats' => $stats]);
        break;

    case 'upload':
        need_post();
        $u = require_user();
        csrf_check();
        if (empty($_FILES['file'])) {
            fail('الملف ما وصلش، غالباً حجمه أكبر من حد السيرفر (' . fmt_bytes(max_bytes()) . ').', 413);
        }
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $msgs = [
                UPLOAD_ERR_INI_SIZE   => 'حجم الملف أكبر من المسموح في إعدادات السيرفر (' . fmt_bytes(max_bytes()) . ').',
                UPLOAD_ERR_FORM_SIZE  => 'حجم الملف كبير.',
                UPLOAD_ERR_PARTIAL    => 'الرفع ما اكتملش، حاول تاني.',
                UPLOAD_ERR_NO_FILE    => 'يرجى اختيار ملف أولاً.',
                UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة مش موجود على السيرفر.',
                UPLOAD_ERR_CANT_WRITE => 'السيرفر مش قادر يكتب الملف.',
            ];
            fail($msgs[$f['error']] ?? 'فشل رفع الملف.', 400);
        }
        if ($f['size'] > max_bytes()) {
            fail('حجم الملف كبير. الحد الأقصى ' . fmt_bytes(max_bytes()) . '.', 413);
        }
        $subject = (string)($_POST['subject'] ?? '');
        if (!in_array($subject, SUBJECTS, true)) {
            fail('المادة غير صحيحة.');
        }
        if (!rate_limit('up:' . $u['email'], 30, 600)) {
            fail('رفعت ملفات كتير في وقت قصير، استنى شوية.', 429);
        }
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', (string)$f['name']);
        $name = trim($name === null ? '' : $name);
        if ($name === '') {
            $name = 'file';
        }
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 180, 'UTF-8') : substr($name, 0, 180);

        db(); // يضمن إنشاء مجلد التخزين
        $stored = bin2hex(random_bytes(16));
        $dest = storage() . '/uploads/' . $stored;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            fail('تعذر حفظ الملف على السيرفر.', 500);
        }
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $m = $fi ? finfo_file($fi, $dest) : false;
            if (is_string($m) && $m !== '') {
                $mime = $m;
            }
        }
        db()->prepare('INSERT INTO files (name, stored, type, size, subject, uploader, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$name, $stored, $mime, (int)$f['size'], $subject, $u['email'], time()]);
        out(['ok' => true]);
        break;

    case 'download':
        require_user();
        $id = (int)($_GET['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM files WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $path = $r ? storage() . '/uploads/' . $r['stored'] : '';
        if (!$r || !is_file($path)) {
            fail('الملف غير موجود.', 404);
        }
        $inline = in_array($r['type'], INLINE_TYPES, true) && empty($_GET['dl']);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $r['name']);
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($inline ? $r['type'] : 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($r['name']));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;

    case 'delete':
        need_post();
        $u = require_user();
        csrf_check();
        $id = (int)(body()['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM files WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            fail('الملف غير موجود.', 404);
        }
        if (!$u['isOwner'] && $r['uploader'] !== $u['email']) {
            fail('مش مسموحلك تحذف الملف ده.', 403);
        }
        @unlink(storage() . '/uploads/' . $r['stored']);
        db()->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
        out(['ok' => true]);
        break;

    case 'clear_all':
        need_post();
        $u = require_user();
        csrf_check();
        if (!$u['isOwner']) {
            fail('للمالك فقط.', 403);
        }
        foreach (db()->query('SELECT stored FROM files')->fetchAll(PDO::FETCH_COLUMN) as $s) {
            @unlink(storage() . '/uploads/' . $s);
        }
        db()->exec('DELETE FROM files');
        out(['ok' => true]);
        break;

    default:
        fail('طلب غير معروف.', 404);
}
