<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

$email = optional_param('email', '', PARAM_EMAIL);
$role = optional_param('role', 'PESERTA', PARAM_TEXT);
$name = optional_param('name', '', PARAM_TEXT);
$target = optional_param('target', '/my/', PARAM_LOCALURL);

if ($email === '') {
    $cookie_data = amto_parse_tatc_session_cookie();
    if ($cookie_data && !empty($cookie_data['email'])) {
        $email = $cookie_data['email'];
        $role = $cookie_data['role'] ?? 'PESERTA';
    }
}

if ($email !== '') {
    if (!empty($_COOKIE['tatc_session'])) {
        $_SESSION['amto_last_tatc_token'] = $_COOKIE['tatc_session'];
    }
    amto_login_moodle_user_from_tatc($email, $role, $name);
}

if (empty($target) || $target[0] !== '/') {
    $target = '/my/';
}

header('Location: ' . $target);
exit;
