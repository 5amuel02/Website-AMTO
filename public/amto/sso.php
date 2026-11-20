<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

$action = optional_param('action', 'login', PARAM_ALPHA);
if ($action === 'logout') {
    unset($_SESSION['amto_last_tatc_token']);
    setcookie('tatc_session', '', time() - 3600, '/', '', false, true);
    if (isloggedin() && !isguestuser()) {
        require_logout();
    }
    $target = optional_param('target', '', PARAM_RAW);
    if ($target !== '') {
        header('Location: ' . $target);
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

if (empty($target) || $target[0] !== '/') {
    $target = '/my/';
}

header('Location: ' . $target);
exit;
