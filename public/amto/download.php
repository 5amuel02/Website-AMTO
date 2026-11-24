<?php
/**
 * AMTO Secure Document Gatekeeper (DP-06, DP-07, DP-09, SCP-01, APP-02).
 * Prevents IDOR and unauthorized direct access to PII documents (KTP, Ijazah) and Controlled Manuals.
 */
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
if (isguestuser()) {
    http_response_code(403);
    throw new moodle_exception('nopermissions', 'error', '', 'Akses Ditolak (DP-07): Tamu (Guest) tidak diizinkan mengunduh dokumen internal AMTO.');
}

$type = required_param('type', PARAM_ALPHA);
$id   = required_param('id', PARAM_INT);
$doc  = optional_param('doc', '', PARAM_ALPHA);

$role = amto_current_role();
global $DB, $USER;

if ($type === 'reg') {
    $reg = $DB->get_record('amto_registrations', ['id' => $id]);
    if (!$reg) {
        http_response_code(404);
        throw new moodle_exception('invalidrecord', 'error');
    }

    // [SCP-01] Record Ownership Check: Trainee hanya boleh mengakses dokumen miliknya sendiri
    $is_owner = ((int)$reg->userid === (int)$USER->id) || (strtolower($reg->username) === strtolower($USER->username));
    $is_authorized_staff = in_array($role, ['admin', 'adminit', 'trainingmanager', 'certofficer', 'qa'], true);

    if (!$is_owner && !$is_authorized_staff) {
        if (function_exists('amto_log_audit_event')) {
            amto_log_audit_event('UNAUTHORIZED_DOC_ACCESS', 'amto_registrations', $id, 'Denied', 'Blocked IDOR attempt on registration document: ' . $doc);
        }
        http_response_code(403);
        throw new moodle_exception('nopermissions', 'error', '', 'Akses Ditolak (SCP-01): Anda tidak memiliki hak akses terhadap dokumen pribadi peserta lain.');
    }

    $field_map = [
        'ktp'       => 'doc_ktp',
        'ijazah'    => 'doc_ijazah',
        'pendukung' => 'doc_pendukung',
    ];
    if (!isset($field_map[$doc])) {
        http_response_code(400);
        throw new moodle_exception('invalidparameter', 'error');
    }

    $field = $field_map[$doc];
    $rel_path = $reg->$field ?? '';
    if (empty($rel_path)) {
        http_response_code(404);
        throw new moodle_exception('filenotfound', 'error');
    }

    $filename = basename($rel_path);
    $candidate_paths = [
        __DIR__ . '/uploads/' . $filename,
        'C:/Users/Asus Tuf Gaming/tatcwebreact/backend/public/documents/' . $filename,
        'C:/Users/Asus Tuf Gaming/tatcwebreact/public/documents/' . $filename,
        __DIR__ . '/../../tatcwebreact/backend/public/documents/' . $filename,
        __DIR__ . '/../../tatcwebreact/public/documents/' . $filename,
    ];
    $fullpath = '';
    foreach ($candidate_paths as $cand) {
        if (is_file($cand)) {
            $fullpath = $cand;
            break;
        }
    }
    if ($fullpath === '') {
        http_response_code(404);
        throw new moodle_exception('filenotfound', 'error');
    }

    if (function_exists('amto_log_audit_event')) {
        amto_log_audit_event('DOCUMENT_VIEW', 'amto_registrations', $id, $filename, 'Authorized access to registration document (' . $doc . ')');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $fullpath) : 'application/octet-stream';
    if ($finfo) {
        finfo_close($finfo);
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullpath));
    header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
    readfile($fullpath);
    exit;
} else if ($type === 'repo') {
    $mod = $DB->get_record('amto_repository', ['id' => $id]);
    if (!$mod || empty($mod->file_path)) {
        http_response_code(404);
        throw new moodle_exception('filenotfound', 'error');
    }

    $filename = basename($mod->file_path);
    $fullpath = __DIR__ . '/repository_files/' . $filename;
    if (!is_file($fullpath)) {
        http_response_code(404);
        throw new moodle_exception('filenotfound', 'error');
    }

    // [DP-09] Verify SHA-256 Integrity Checksum if stored
    if (!empty($mod->file_hash)) {
        $actual_hash = hash_file('sha256', $fullpath);
        if (!hash_equals($mod->file_hash, $actual_hash)) {
            http_response_code(500);
            throw new moodle_exception('error', 'error', '', 'Peringatan Integritas Dokumen (DP-09): Checksum SHA-256 dokumen tidak cocok!');
        }
    }

    if (function_exists('amto_log_audit_event')) {
        amto_log_audit_event('REPO_DOWNLOAD', 'amto_repository', $id, $mod->module_code, 'Downloaded controlled training manual: ' . $filename);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $fullpath) : 'application/octet-stream';
    if ($finfo) {
        finfo_close($finfo);
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: private, max-age=300');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullpath));
    header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
    readfile($fullpath);
    exit;
}

http_response_code(400);
throw new moodle_exception('invalidparameter', 'error');
