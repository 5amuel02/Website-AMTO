<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

/**
 * [SEC-SSO-02] APP-01 & APP-04: Validasi ketat URL redirect SSO (Allowlist Origin & Relative Path)
 * untuk mencegah celah Open Redirect Phishing (termasuk bypass protocol-relative //domain.com) dan Header Injection.
 */
function amto_sanitize_sso_redirect_target(string $raw_target, string $fallback = '/login/index.php'): string {
    $clean = trim(str_replace(["\r", "\n", "\0"], '', $raw_target));
    if ($clean === '') {
        return '';
    }

    // 1. Izinkan path relatif internal yang diawali satu '/' (tolak '//' atau '/\')
    if ($clean[0] === '/' && !preg_match('#^[/\\\\]{2}#', $clean)) {
        return $clean;
    }

    // 2. Izinkan origin internal Portal TATC (Port 5173) & LMS Moodle (Port 8080) pada host yang sah
    $parsed = parse_url($clean);
    if (is_array($parsed) && !empty($parsed['scheme']) && !empty($parsed['host'])) {
        $scheme = strtolower($parsed['scheme']);
        $host   = strtolower($parsed['host']);
        $port   = isset($parsed['port']) ? (int)$parsed['port'] : ($scheme === 'https' ? 443 : 80);

        $current_host = !empty($_SERVER['HTTP_HOST']) ? strtolower(explode(':', $_SERVER['HTTP_HOST'])[0]) : 'localhost';
        $allowed_hosts = array_unique(['localhost', '127.0.0.1', $current_host]);
        $allowed_ports = [80, 443, 5173, 8080];

        if (in_array($scheme, ['http', 'https'], true) &&
            in_array($host, $allowed_hosts, true) &&
            in_array($port, $allowed_ports, true)) {
            return $clean;
        }
    }

    // 3. Blokir domain eksternal yang tidak dikenal & catat ke Audit Trail
    if (function_exists('amto_log_audit_event')) {
        amto_log_audit_event(
            'SSO_OPEN_REDIRECT_BLOCKED',
            'sso',
            0,
            'blocked_target=' . substr($clean, 0, 180),
            'Menolak percobaan Open Redirect menuju domain eksternal yang tidak terdaftar dalam allowlist AMTO.'
        );
    }
    return $fallback;
}

$action = optional_param('action', 'login', PARAM_ALPHA);
if ($action === 'logout') {
    unset($_SESSION['amto_last_tatc_token']);
    setcookie('tatc_session', '', time() - 3600, '/', '', false, true);
    if (isloggedin() && !isguestuser()) {
        // [SEC-SSO-03] LOG-01: Catat aktivitas Single Logout ke tabel Audit Trail sebelum sesi dihapus
        if (function_exists('amto_log_audit_event')) {
            amto_log_audit_event(
                'SSO_LOGOUT',
                'user',
                (int)($USER->id ?? 0),
                'username=' . ($USER->username ?? 'unknown'),
                'Sesi pengguna diakhiri melalui mekanisme Single Logout SSO (LOG-01).'
            );
        }
        require_logout();
    }
    $raw_target = optional_param('target', '', PARAM_RAW);
    $safe_target = amto_sanitize_sso_redirect_target($raw_target, '/login/index.php');
    if ($safe_target !== '') {
        header('Location: ' . $safe_target);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Moodle session logged out']);
    exit;
}

$req_email = strtolower(trim(optional_param('email', '', PARAM_EMAIL)));
$req_role  = strtoupper(trim(optional_param('role', 'PESERTA', PARAM_TEXT)));
$name      = optional_param('name', '', PARAM_TEXT);
$target    = optional_param('target', '/my/', PARAM_LOCALURL);

// [SEC-SSO-01] IAM-01, APP-02 & SEC-04 (Deny by Default):
// Wajib memvalidasi tanda tangan kriptografis HMAC-SHA256 dari cookie tatc_session.
// Dilarang mempercayai parameter $_GET['email'] atau $_GET['role'] tanpa verifikasi HMAC token
// untuk mencegah celah Critical Authentication Bypass & Privilege Escalation ke akun Super Admin.
$cookie_data = amto_parse_tatc_session_cookie();

if ($cookie_data && !empty($cookie_data['email'])) {
    $verified_email = strtolower(trim((string)$cookie_data['email']));
    $verified_role  = strtoupper(trim((string)($cookie_data['role'] ?? 'PESERTA')));

    // Deteksi jika parameter URL mencoba memalsukan (spoofing) email yang berbeda dari token HMAC
    if ($req_email !== '' && $req_email !== $verified_email) {
        if (function_exists('amto_log_audit_event')) {
            amto_log_audit_event(
                'SSO_SPOOF_ATTEMPT_BLOCKED',
                'user',
                0,
                "url_email={$req_email} != token_email={$verified_email}",
                'Percobaan manipulasi parameter email SSO ditolak; menggunakan identitas token HMAC resmi.'
            );
        }
    }

    $_SESSION['amto_last_tatc_token'] = $_COOKIE['tatc_session'];
    amto_login_moodle_user_from_tatc($verified_email, $verified_role, $name);
} else if (!isloggedin() || isguestuser()) {
    // Tidak memiliki token HMAC tatc_session yang valid dan belum login di Moodle: blokir bypass URL
    if ($req_email !== '' && function_exists('amto_log_audit_event')) {
        amto_log_audit_event(
            'SSO_AUTH_BYPASS_BLOCKED',
            'user',
            0,
            "unverified_email={$req_email}, role={$req_role}",
            'Menolak percobaan login SSO via parameter URL tanpa tanda tangan kriptografis HMAC-SHA256 tatc_session.'
        );
    }
    header('Location: /login/index.php');
    exit;
}

$safe_login_target = amto_sanitize_sso_redirect_target((string)$target, '/my/');
if ($safe_login_target === '') {
    $safe_login_target = '/my/';
}

header('Location: ' . $safe_login_target);
exit;
