<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

amto_auto_sync_from_tatc_cookie();
require_login();
amto_ensure_tables();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/amto/repository.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('TATC Portal – Kelas & Silabus Materi Pelatihan AMTO');
$PAGE->set_heading('Kelas & Silabus Materi Pelatihan');

$role = amto_current_role();
$rbac_role = amto_current_rbac_role();
$can_manage = in_array($rbac_role, ['admin', 'trainingmanager', 'instructor', 'assessor'], true);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$class_id = optional_param('class_id', 0, PARAM_INT);
$cat_filter = optional_param('cat', 'all', PARAM_TEXT);
$msg = optional_param('msg', '', PARAM_TEXT);
$notice_msg = $msg !== '' ? s($msg) : '';
$notice_type = 'success';

// Helper to handle secure repository file upload with MIME validation & SHA-256 Checksum (DP-06, DP-09, APP-06)
function amto_save_repo_file(string $modcode): array {
    if (empty($_FILES['repo_file']['name']) || $_FILES['repo_file']['error'] !== UPLOAD_ERR_OK) {
        return ['path' => '', 'size' => '', 'hash' => ''];
    }
    $allowed_ext = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'mp4', 'zip', 'png', 'jpg', 'jpeg'];
    $allowed_mimes = [
        'application/pdf',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'video/mp4',
        'application/zip',
        'application/x-zip-compressed',
        'image/png',
        'image/jpeg',
    ];
    $orig_name = basename((string)$_FILES['repo_file']['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return ['path' => '', 'size' => '', 'hash' => ''];
    }
    $bytes = (int)$_FILES['repo_file']['size'];
    if ($bytes <= 0 || $bytes > 25 * 1024 * 1024) {
        return ['path' => '', 'size' => '', 'hash' => ''];
    }
    $tmp_path = $_FILES['repo_file']['tmp_name'];
    if (!is_uploaded_file($tmp_path)) {
        return ['path' => '', 'size' => '', 'hash' => ''];
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = $finfo ? finfo_file($finfo, $tmp_path) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!in_array($real_mime, $allowed_mimes, true)) {
            return ['path' => '', 'size' => '', 'hash' => ''];
        }
    }
    $upload_dir = __DIR__ . '/repository_files';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0750, true);
    }
    $clean_code = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($modcode));
    $safe_name = $clean_code . '_' . time() . '.' . $ext;
    $dest = $upload_dir . '/' . $safe_name;
    if (move_uploaded_file($tmp_path, $dest)) {
        $size_kb = round($bytes / 1024, 1);
        $size_str = $size_kb >= 1024 ? round($size_kb / 1024, 2) . ' MB' : $size_kb . ' KB';
        $sha256 = hash_file('sha256', $dest) ?: '';
        return ['path' => '/amto/repository_files/' . $safe_name, 'size' => $size_str, 'hash' => $sha256];
    }
    return ['path' => '', 'size' => '', 'hash' => ''];
}

// Handle POST Actions (Teacher / Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    require_sesskey();

    // 1. TAHAP 1: Buat Wadah Bab Baru (Simpel: Urutan Bab + Judul Topik Bab + Deskripsi Singkat)
    if ($action === 'create_chapter') {
        $target_class_id = required_param('target_class_id', PARAM_INT);
        $batch_code = trim(optional_param('batch_code', '', PARAM_TEXT));
        $chapter_no = trim(optional_param('chapter_no', 'Bab 1', PARAM_TEXT));
        $title = trim(optional_param('title', '', PARAM_TEXT));
        $description = trim(optional_param('description', '', PARAM_TEXT));

        if ($title !== '') {
            $ch = new stdClass();
            $ch->schedule_id = $target_class_id;
            $ch->batch_code  = $batch_code;
            $ch->chapter_no  = $chapter_no ?: 'Bab 1';
            $ch->title       = $title;
            $ch->description = $description;
            $ch->status      = 'Active';
            $ch->timecreated = time();
            $new_cid = $DB->insert_record('amto_chapters', $ch);

            if (function_exists('amto_log_audit_event')) {
                amto_log_audit_event('CHAPTER_CREATE', 'amto_chapters', (int)$new_cid, "chapter={$ch->chapter_no}, title={$ch->title}", "Created syllabus chapter in batch {$batch_code}");
            }
            $m = "{$ch->chapter_no}: {$ch->title} berhasil dibuat. Sekarang Anda dapat menambahkan Materi atau Ujian di dalam {$ch->chapter_no}.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 2. Edit Wadah Bab
    if ($action === 'edit_chapter') {
        $chapter_id = required_param('chapter_id', PARAM_INT);
        $target_class_id = required_param('target_class_id', PARAM_INT);
        $ch = $DB->get_record('amto_chapters', ['id' => $chapter_id]);
        if ($ch) {
            $old_cno = $ch->chapter_no;
            $ch->chapter_no  = trim(optional_param('chapter_no', $ch->chapter_no, PARAM_TEXT));
            $ch->title       = trim(optional_param('title', $ch->title, PARAM_TEXT));
            $ch->description = trim(optional_param('description', (string)$ch->description, PARAM_TEXT));
            $DB->update_record('amto_chapters', $ch);

            // Sync chapter_no on child materials and exams
            $DB->execute("UPDATE {amto_repository} SET chapter_no = ? WHERE chapter_id = ?", [$ch->chapter_no, $chapter_id]);
            $DB->execute("UPDATE {amto_exams} SET chapter_no = ? WHERE chapter_id = ?", [$ch->chapter_no, $chapter_id]);

            $m = "Data {$ch->chapter_no} ({$ch->title}) berhasil diperbarui.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 3. Hapus Wadah Bab
    if ($action === 'delete_chapter') {
        $chapter_id = required_param('chapter_id', PARAM_INT);
        $target_class_id = required_param('target_class_id', PARAM_INT);
        $ch = $DB->get_record('amto_chapters', ['id' => $chapter_id]);
        if ($ch) {
            $ch->status = 'Voided';
            $DB->update_record('amto_chapters', $ch);
            $DB->execute("UPDATE {amto_repository} SET status = 'Voided' WHERE chapter_id = ?", [$chapter_id]);
            $DB->execute("UPDATE {amto_exams} SET status = 'Voided' WHERE chapter_id = ?", [$chapter_id]);
            $m = "{$ch->chapter_no} ({$ch->title}) beserta isinya telah dihapus dari silabus kelas.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 4. TAHAP 2A: Tambah Materi ke Dalam Bab Tertentu
    if ($action === 'add_material_to_chapter') {
        $target_class_id = required_param('target_class_id', PARAM_INT);
        $chapter_id = required_param('chapter_id', PARAM_INT);
        $chapter_no = trim(optional_param('chapter_no', 'Bab 1', PARAM_TEXT));
        $batch_code = trim(optional_param('batch_code', 'Semua Batch', PARAM_TEXT));
        $category_type = trim(optional_param('category_type', 'Basic License', PARAM_TEXT));
        $title = trim(optional_param('title', '', PARAM_TEXT));
        $module_code = strtoupper(trim(optional_param('module_code', '', PARAM_TEXT)));
        $file_type = trim(optional_param('file_type', 'PDF Training Manual', PARAM_TEXT));
        $description = trim(optional_param('description', '', PARAM_TEXT));

        if ($module_code === '') {
            $module_code = 'MAT-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $chapter_no));
        }

        if ($title !== '') {
            $upload_res = amto_save_repo_file($module_code);
            $rec = new stdClass();
            $rec->module_code    = $module_code;
            $rec->title          = $title;
            $rec->category_type  = $category_type;
            $rec->batch_code     = $batch_code;
            $rec->chapter_id     = $chapter_id;
            $rec->chapter_no     = $chapter_no;
            $rec->file_type      = $file_type;
            $rec->file_path      = $upload_res['path'];
            $rec->file_size      = $upload_res['size'] ?: 'Materi Digital';
            $rec->file_hash      = $upload_res['hash'] ?? '';
            $rec->doc_version    = 'Rev. 01 - 2026';
            $rec->description    = $description;
            $rec->has_assessment = 'Tidak Ada Assessment';
            $rec->uploaded_by    = $USER->id;
            $rec->uploader_name  = fullname($USER);
            $rec->status         = 'Published';
            $rec->timecreated    = time();

            $new_mod_id = $DB->insert_record('amto_repository', $rec);
            if (function_exists('amto_sync_cross_db')) {
                amto_sync_cross_db(true);
            }
            $m = "Materi \"{$title}\" berhasil ditambahkan ke dalam {$chapter_no}.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 5. Edit Materi di Dalam Bab
    if ($action === 'edit_material') {
        $mod_id = required_param('mod_id', PARAM_INT);
        $target_class_id = required_param('target_class_id', PARAM_INT);
        $mod = $DB->get_record('amto_repository', ['id' => $mod_id]);
        if ($mod) {
            $mod->title       = trim(optional_param('title', $mod->title, PARAM_TEXT));
            $mod->module_code = strtoupper(trim(optional_param('module_code', $mod->module_code, PARAM_TEXT)));
            $mod->file_type   = trim(optional_param('file_type', $mod->file_type, PARAM_TEXT));
            $mod->description = trim(optional_param('description', (string)$mod->description, PARAM_TEXT));
            $upload_res = amto_save_repo_file($mod->module_code ?: 'mat');
            if ($upload_res['path'] !== '') {
                $mod->file_path = $upload_res['path'];
                $mod->file_size = $upload_res['size'];
                $mod->file_hash = $upload_res['hash'];
            }
            $DB->update_record('amto_repository', $mod);
            $m = "Materi \"{$mod->title}\" berhasil diperbarui.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 6. Hapus Materi dari Bab
    if ($action === 'delete_module') {
        $mod_id = required_param('mod_id', PARAM_INT);
        $target_class_id = optional_param('target_class_id', 0, PARAM_INT);
        $mod = $DB->get_record('amto_repository', ['id' => $mod_id]);
        if ($mod) {
            $mod->status = 'Voided';
            $DB->update_record('amto_repository', $mod);
            $m = "Materi \"{$mod->title}\" telah dihapus dari {$mod->chapter_no}.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 7. TAHAP 2B: Buat Ujian / Assessment di Dalam Bab Tertentu
    if ($action === 'add_exam_to_chapter') {
        $target_class_id = required_param('target_class_id', PARAM_INT);
        $chapter_id = required_param('chapter_id', PARAM_INT);
        $chapter_no = trim(optional_param('chapter_no', 'Bab 1', PARAM_TEXT));
        $batch_code = trim(optional_param('batch_code', '', PARAM_TEXT));
        $category_type = trim(optional_param('category_type', 'Basic License', PARAM_TEXT));
        $title = trim(optional_param('title', '', PARAM_TEXT));
        $exam_code = strtoupper(trim(optional_param('exam_code', '', PARAM_TEXT)));
        $exam_type = trim(optional_param('exam_type', 'Written CBT (Multiple Choice)', PARAM_TEXT));
        $duration_mins = max(15, optional_param('duration_mins', 60, PARAM_INT));
        $passing_grade = max(50, min(100, optional_param('passing_grade', 75, PARAM_INT)));
        $exam_date = trim(optional_param('exam_date', date('Y-m-d', strtotime('+7 days')), PARAM_TEXT));

        if ($exam_code === '') {
            $exam_code = 'CBT-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $chapter_no)) . '-' . rand(10, 99);
        }

        if ($title !== '') {
            $e = new stdClass();
            $e->exam_code     = $exam_code;
            $e->title         = $title;
            $e->category_type = $category_type;
            $e->batch_code    = $batch_code;
            $e->chapter_id    = $chapter_id;
            $e->chapter_no    = $chapter_no;
            $e->exam_type     = $exam_type;
            $e->passing_grade = $passing_grade;
            $e->duration_mins = $duration_mins;
            $e->exam_date     = $exam_date;
            $e->assessor_name = fullname($USER);
            $e->status        = 'Active';
            $e->timecreated   = time();

            $eid = $DB->insert_record('amto_exams', $e);
            if (function_exists('amto_sync_cross_db')) {
                amto_sync_cross_db(true);
            }
            $m = "Quiz / Ujian \"[{$e->exam_code}] {$e->title}\" berhasil dibuat di {$chapter_no}. Klik tombol '+ Kelola Soal & Gambar' pada ujian tersebut untuk menambahkan soal Pilihan Ganda / Essay.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 7b. TAHAP 2C: Buat Assignment (Penugasan) Lengkap dengan Deadline di Dalam Bab
    if ($action === 'add_assignment_to_chapter') {
        $target_class_id = required_param('target_class_id', PARAM_INT);
        $chapter_id = required_param('chapter_id', PARAM_INT);
        $chapter_no = trim(optional_param('chapter_no', 'Bab 1', PARAM_TEXT));
        $batch_code = trim(optional_param('batch_code', '', PARAM_TEXT));
        $title = trim(optional_param('title', '', PARAM_TEXT));
        $description = trim(optional_param('description', '', PARAM_TEXT));
        $deadline_at = trim(optional_param('deadline_at', date('Y-m-d\TH:i', strtotime('+7 days')), PARAM_TEXT));
        $passing_grade = max(50, min(100, optional_param('passing_grade', 75, PARAM_INT)));

        if ($title !== '') {
            $upload_res = amto_save_repo_file('assign_' . $chapter_id);
            $a = new stdClass();
            $a->schedule_id     = $target_class_id;
            $a->batch_code      = $batch_code;
            $a->chapter_id      = $chapter_id;
            $a->chapter_no      = $chapter_no;
            $a->title           = $title;
            $a->description     = $description;
            $a->attachment_path = $upload_res['path'];
            $a->deadline_at     = str_replace('T', ' ', $deadline_at);
            $a->passing_grade   = $passing_grade;
            $a->created_by      = fullname($USER);
            $a->status          = 'Active';
            $a->timecreated     = time();
            $DB->insert_record('amto_assignments', $a);

            $m = "Assignment (Penugasan) \"{$title}\" dengan Deadline {$a->deadline_at} berhasil ditambahkan ke {$chapter_no}.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 7c. Edit Assignment & Deadline di Dalam Bab
    if ($action === 'edit_assignment') {
        $assign_id = required_param('assign_id', PARAM_INT);
        $target_class_id = required_param('target_class_id', PARAM_INT);
        $a = $DB->get_record('amto_assignments', ['id' => $assign_id]);
        if ($a) {
            $a->title         = trim(optional_param('title', $a->title, PARAM_TEXT));
            $a->description   = trim(optional_param('description', (string)$a->description, PARAM_TEXT));
            $raw_dl           = trim(optional_param('deadline_at', $a->deadline_at, PARAM_TEXT));
            $a->deadline_at   = str_replace('T', ' ', $raw_dl);
            $a->passing_grade = max(50, min(100, optional_param('passing_grade', (int)$a->passing_grade, PARAM_INT)));
            $upload_res = amto_save_repo_file('assign_' . $assign_id);
            if ($upload_res['path'] !== '') {
                $a->attachment_path = $upload_res['path'];
            }
            $DB->update_record('amto_assignments', $a);
            $m = "Assignment \"{$a->title}\" & Deadline ({$a->deadline_at}) berhasil diperbarui.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 7d. Hapus Assignment dari Bab
    if ($action === 'delete_assignment') {
        $assign_id = required_param('assign_id', PARAM_INT);
        $target_class_id = optional_param('target_class_id', 0, PARAM_INT);
        $a = $DB->get_record('amto_assignments', ['id' => $assign_id]);
        if ($a) {
            $a->status = 'Voided';
            $DB->update_record('amto_assignments', $a);
            $DB->delete_records('amto_assignment_submissions', ['assignment_id' => $assign_id]);
            $m = "Assignment \"{$a->title}\" telah dihapus dari silabus.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }

    // 8. Hapus Ujian dari Bab
    if ($action === 'delete_exam') {
        $exam_id = required_param('exam_id', PARAM_INT);
        $target_class_id = optional_param('target_class_id', 0, PARAM_INT);
        $ex = $DB->get_record('amto_exams', ['id' => $exam_id]);
        if ($ex) {
            $ex->status = 'Voided';
            $DB->update_record('amto_exams', $ex);
            $DB->delete_records('amto_exam_results', ['exam_id' => $exam_id]);
            try {
                $DB->execute("DELETE FROM tatc_web.exams WHERE code = ?", [$ex->exam_code]);
            } catch (Throwable $e) {
            }
            $m = "Ujian [{$ex->exam_code}] {$ex->title} beserta nilai terkait telah dihapus dari silabus.";
            header('Location: /amto/repository.php?class_id=' . $target_class_id . '&msg=' . urlencode($m));
            exit;
        }
    }
}

$raw_schedules = $DB->get_records('amto_schedules', null, 'id DESC');
$schedules = [];
foreach ($raw_schedules as $sc) {
    if (in_array(strtolower((string)$sc->status), ['void', 'voided'], true)) {
        continue;
    }
    $schedules[$sc->id] = $sc;
}

$raw_chapters = $DB->get_records('amto_chapters', null, 'id ASC');
$all_chapters = [];
foreach ($raw_chapters as $rc) {
    if (in_array(strtolower((string)$rc->status), ['void', 'voided'], true)) {
        continue;
    }
    $all_chapters[$rc->id] = $rc;
}

$raw_modules = $DB->get_records('amto_repository', null, 'id ASC');
$all_modules = [];
foreach ($raw_modules as $rm) {
    if (in_array(strtolower((string)$rm->status), ['void', 'voided'], true)) {
        continue;
    }
    $all_modules[] = $rm;
}

$raw_exams = $DB->get_records('amto_exams', null, 'id ASC');
$all_exams = [];
foreach ($raw_exams as $re) {
    if (in_array(strtolower((string)$re->status), ['void', 'voided'], true)) {
        continue;
    }
    $all_exams[] = $re;
}

$raw_assignments = $DB->get_records('amto_assignments', null, 'id ASC');
$all_assignments = [];
foreach ($raw_assignments as $ra) {
    if (in_array(strtolower((string)$ra->status), ['void', 'voided'], true)) {
        continue;
    }
    $all_assignments[] = $ra;
}

$selected_class = ($class_id > 0 && isset($schedules[$class_id])) ? $schedules[$class_id] : null;

// Auto-enroll active LMS student into the class when they open/enter the class syllabus
if ($selected_class && $role === 'student' && isloggedin() && !isguestuser()) {
    $already_in_class = $DB->get_record_select(
        'amto_registrations',
        'schedule_id = :sid AND (userid = :uid OR LOWER(username) = LOWER(:uname) OR LOWER(email) = LOWER(:uemail)) AND LOWER(status) NOT IN (\'void\', \'voided\')',
        ['sid' => $selected_class->id, 'uid' => $USER->id, 'uname' => $USER->username, 'uemail' => $USER->email],
        '*',
        IGNORE_MULTIPLE
    );
    if (!$already_in_class) {
        $general_reg = $DB->get_record_select(
            'amto_registrations',
            'schedule_id = 0 AND (userid = :uid OR LOWER(username) = LOWER(:uname) OR LOWER(email) = LOWER(:uemail)) AND LOWER(status) NOT IN (\'void\', \'voided\')',
            ['uid' => $USER->id, 'uname' => $USER->username, 'uemail' => $USER->email],
            '*',
            IGNORE_MULTIPLE
        );
        if ($general_reg) {
            $general_reg->schedule_id = (int)$selected_class->id;
            $general_reg->program_name = $selected_class->batch_code . ' – ' . $selected_class->program_name;
            $general_reg->timemodified = time();
            $DB->update_record('amto_registrations', $general_reg);
        }
    }
}

// Auto-migrate any orphaned materials in this class into a Wadah Bab (mdl_amto_chapters) if not linked yet
if ($selected_class) {
    foreach ($all_modules as $m) {
        if (strcasecmp(trim($m->batch_code), trim($selected_class->batch_code)) === 0 && empty($m->chapter_id)) {
            $cno = !empty($m->chapter_no) ? trim($m->chapter_no) : 'Bab 1';
            $found_ch = null;
            foreach ($all_chapters as $ech) {
                if ((int)$ech->schedule_id === (int)$selected_class->id && strcasecmp(trim($ech->chapter_no), $cno) === 0) {
                    $found_ch = $ech;
                    break;
                }
            }
            if (!$found_ch) {
                $nc = new stdClass();
                $nc->schedule_id = (int)$selected_class->id;
                $nc->batch_code  = $selected_class->batch_code;
                $nc->chapter_no  = $cno;
                $nc->title       = $m->title ?: $cno;
                $nc->description = $m->description ?: '';
                $nc->status      = 'Active';
                $nc->timecreated = time();
                $nc->id = $DB->insert_record('amto_chapters', $nc);
                $all_chapters[$nc->id] = $nc;
                $found_ch = $nc;
            }
            $m->chapter_id = (int)$found_ch->id;
            $DB->update_record('amto_repository', $m);
        }
    }
}

echo $OUTPUT->header();
echo amto_render_shared_styles();
?>

<style>
.celoe-course-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 20px;
    margin-top: 8px;
}
.celoe-course-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.22s ease;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
    text-decoration: none !important;
    color: inherit !important;
}
.celoe-course-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 14px 28px rgba(185, 28, 28, 0.12);
    border-color: #fca5a5;
}
.celoe-card-banner {
    background: linear-gradient(135deg, #991b1b 0%, #dc2626 60%, #b91c1c 100%);
    color: #ffffff;
    padding: 18px 20px;
}
.celoe-card-code {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.3);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.celoe-card-title {
    font-size: 18px;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 4px 0;
    line-height: 1.3;
}
.celoe-card-mode {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.88);
    font-weight: 500;
}
.celoe-card-body {
    padding: 18px 20px;
    flex: 1;
}
.celoe-meta-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 13px;
    color: #334155;
    margin-bottom: 16px;
}
.celoe-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
}
.celoe-syllabus-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
}
.celoe-card-footer {
    padding: 13px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.celoe-chapter-card {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 16px;
    margin-bottom: 18px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
}
.celoe-chapter-header {
    background: #f8fafc;
    padding: 15px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.celoe-chapter-badge {
    background: #b91c1c;
    color: #ffffff;
    font-weight: 800;
    font-size: 12px;
    padding: 5px 12px;
    border-radius: 8px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.celoe-chapter-body {
    padding: 18px 20px;
}
.celoe-item-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 13px 16px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    margin-bottom: 10px;
}
.celoe-item-row:last-child {
    margin-bottom: 0;
}
.celoe-inline-drawer {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 14px;
}
</style>

<div class="tatc-wrap">

<?php if ($selected_class): ?>
    <?php
    // Filter Wadah Bab for this class
    $class_chapters = [];
    foreach ($all_chapters as $ch) {
        if ((int)$ch->schedule_id === (int)$selected_class->id ||
            strcasecmp(trim($ch->batch_code), trim($selected_class->batch_code)) === 0) {
            $class_chapters[] = $ch;
        }
    }
    usort($class_chapters, function($a, $b) {
        return strnatcasecmp($a->chapter_no, $b->chapter_no);
    });

    // Next suggested Bab number
    $next_bab_num = count($class_chapters) + 1;
    $next_bab_label = 'Bab ' . $next_bab_num;

    // Collect materials and exams for this class
    $class_modules = [];
    foreach ($all_modules as $m) {
        if (strcasecmp(trim($m->batch_code), trim($selected_class->batch_code)) === 0 ||
            strcasecmp(trim($m->batch_code), 'Semua Batch') === 0) {
            $class_modules[] = $m;
        }
    }

    $class_exams = [];
    foreach ($all_exams as $ex) {
        if (strcasecmp(trim($ex->batch_code), trim($selected_class->batch_code)) === 0 ||
            stripos($selected_class->category_type, $ex->category_type) !== false ||
            stripos($ex->category_type, $selected_class->category_type) !== false) {
            $class_exams[] = $ex;
        }
    }

    $class_assignments = [];
    foreach ($all_assignments as $asg) {
        if ((int)$asg->schedule_id === (int)$selected_class->id ||
            strcasecmp(trim($asg->batch_code), trim($selected_class->batch_code)) === 0) {
            $class_assignments[] = $asg;
        }
    }
    ?>

    <!-- LEVEL 2: HALAMAN SILABUS KELAS BERJENJANG (ALA CeLOE LMS TELKOM UNIVERSITY) -->
    <div class="mb-3">
        <a href="/amto/repository.php" class="tatc-btn-outline" style="padding: 8px 16px; font-size: 13px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <span>Kembali ke Daftar Kelas Saya</span>
        </a>
    </div>

    <div class="tatc-hero">
        <div>
            <div class="tatc-hero-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                <span>SILABUS KELAS &bull; <?php echo s($selected_class->category_type); ?> &bull; KODE BATCH: <?php echo s($selected_class->batch_code); ?></span>
            </div>
            <h1><?php echo s($selected_class->program_name); ?></h1>
            <p>
                Instruktur Pengampu: <strong><?php echo s($selected_class->instructor_name); ?></strong> &nbsp;|&nbsp;
                Ruangan / Hangar: <strong><?php echo s($selected_class->room_location); ?></strong> &nbsp;|&nbsp;
                Periode: <strong><?php echo s($selected_class->start_date); ?> s/d <?php echo s($selected_class->end_date); ?></strong> (<?php echo s($selected_class->schedule_time); ?>)
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($can_manage): ?>
            <button type="button" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:800;" onclick="var f=document.getElementById('tatcCreateBabPanel'); f.style.display = (f.style.display==='none' ? 'block' : 'none');">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>+ Tambah Bab Baru</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($notice_msg !== ''): ?>
        <div class="alert alert-<?php echo $notice_type; ?> mb-4" style="border-radius:12px; font-size:14px;">
            <?php echo $notice_msg; ?>
        </div>
    <?php endif; ?>

    <?php if ($can_manage): ?>
    <!-- TAHAP 1: FORM SIMPEL UNTUK MEMBUAT WADAH BAB BARU (MISAL: BAB 1, BAB 2, DST.) -->
    <div id="tatcCreateBabPanel" class="tatc-panel" style="display: <?php echo empty($class_chapters) ? 'block' : 'none'; ?>; border: 2px solid #fecaca;">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Buat Bab / Pertemuan Silabus Baru</h2>
                <p class="tatc-panel-sub">Tambahkan wadah Bab terlebih dahulu (misalnya <strong>Bab 1</strong>, <strong>Bab 2</strong>). Setelah Bab masuk ke daftar di bawah, Anda dapat menambahkan <strong>Materi</strong> atau <strong>Ujian</strong> di dalam Bab tersebut.</p>
            </div>
            <span class="tatc-badge tatc-badge-open">Kelas: <?php echo s($selected_class->program_name); ?></span>
        </div>

        <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="create_chapter">
            <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
            <input type="hidden" name="batch_code" value="<?php echo s($selected_class->batch_code); ?>">

            <div class="tatc-form-grid mb-3">
                <div>
                    <label class="tatc-label">Urutan Bab *</label>
                    <select name="chapter_no" class="tatc-select">
                        <?php for ($bi = 1; $bi <= 12; $bi++): ?>
                            <?php $bl = 'Bab ' . $bi; ?>
                            <option value="<?php echo $bl; ?>" <?php echo ($bl === $next_bab_label) ? 'selected' : ''; ?>><?php echo $bl; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Judul Topik Bab *</label>
                    <input type="text" name="title" class="tatc-input" placeholder="Contoh: Pengenalan Pesawat & Regulasi CASR 147" required>
                </div>
                <div>
                    <label class="tatc-label">Deskripsi Singkat Bab (Opsional)</label>
                    <input type="text" name="description" class="tatc-input" placeholder="Contoh: Pokok bahasan dasar dan pengantar materi">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="tatc-btn-outline" onclick="document.getElementById('tatcCreateBabPanel').style.display='none';">Batal</button>
                <button type="submit" class="tatc-btn-red">+ Simpan &amp; Buat Bab</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- TAHAP 2: DAFTAR WADAH BAB SILABUS (BAB 1, BAB 2, DST.) BESERTA TOMBOL TAMBAH MATERI & TAMBAH UJIAN DI DALAMNYA -->
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Struktur Silabus Pembelajaran, Materi &amp; Assessment</h2>
                <p class="tatc-panel-sub">
                    <?php if ($can_manage): ?>
                        Gunakan tombol <strong>"+ Tambah Materi"</strong> atau <strong>"+ Buat Ujian"</strong> pada masing-masing kotak Bab di bawah ini untuk mengisi konten pembelajaran.
                    <?php else: ?>
                        Daftar urutan Bab pembelajaran beserta modul materi dan ujian/assessment pada kelas <strong><?php echo s($selected_class->program_name); ?></strong>.
                    <?php endif; ?>
                </p>
            </div>
            <span class="tatc-badge tatc-badge-ongoing"><?php echo count($class_chapters); ?> Bab Silabus</span>
        </div>

        <?php if (empty($class_chapters)): ?>
            <div class="text-center py-5">
                <div style="font-weight: 800; color: #0f172a; font-size: 16px; margin-bottom: 6px;">
                    Belum Ada Bab Silabus pada Kelas "<?php echo s($selected_class->program_name); ?>"
                </div>
                <p class="text-muted mb-0" style="font-size: 13.5px; max-width: 560px; margin: 0 auto;">
                    <?php if ($can_manage): ?>
                        Silakan buat <strong>Bab 1</strong> terlebih dahulu melalui form <strong>"Buat Bab / Pertemuan Silabus Baru"</strong> di atas.
                    <?php else: ?>
                        Instruktur atau Admin belum menambahkan Bab silabus pada kelas ini.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($class_chapters as $ch): ?>
                <?php
                $cid = (int)$ch->id;
                // Find materials inside this Bab
                $ch_materials = [];
                foreach ($class_modules as $m) {
                    if ((int)$m->chapter_id === $cid ||
                        (empty($m->chapter_id) && strcasecmp(trim($m->chapter_no), trim($ch->chapter_no)) === 0)) {
                        $ch_materials[] = $m;
                    }
                }
                // Find exams inside this Bab
                $ch_exams = [];
                foreach ($class_exams as $ex) {
                    if ((int)($ex->chapter_id ?? 0) === $cid ||
                        (empty($ex->chapter_id) && strcasecmp(trim($ex->chapter_no ?? 'Bab 1'), trim($ch->chapter_no)) === 0)) {
                        $ch_exams[] = $ex;
                    }
                }
                // Find assignments inside this Bab
                $ch_assignments = [];
                foreach ($class_assignments as $asg) {
                    if ((int)($asg->chapter_id ?? 0) === $cid ||
                        (empty($asg->chapter_id) && strcasecmp(trim($asg->chapter_no ?? 'Bab 1'), trim($ch->chapter_no)) === 0)) {
                        $ch_assignments[] = $asg;
                    }
                }
                ?>

                <div class="celoe-chapter-card">
                    <!-- HEADER BAB -->
                    <div class="celoe-chapter-header">
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="celoe-chapter-badge"><?php echo s($ch->chapter_no); ?></span>
                                <strong style="font-size: 16px; color: #0f172a;"><?php echo s($ch->title); ?></strong>
                                <span class="tatc-doc-pill" style="margin:0;">
                                    <?php echo count($ch_materials); ?> Materi &bull; <?php echo count($ch_exams); ?> Quiz/Ujian &bull; <?php echo count($ch_assignments); ?> Assignment
                                </span>
                            </div>
                            <?php if (!empty($ch->description)): ?>
                                <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                                    <?php echo s($ch->description); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($can_manage): ?>
                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                <button type="button" class="tatc-btn-red" style="padding: 6px 12px; font-size: 12px;" onclick="var d=document.getElementById('addMatDrawer_<?php echo $cid; ?>'); d.style.display=(d.style.display==='none'?'block':'none'); document.getElementById('addExDrawer_<?php echo $cid; ?>').style.display='none'; document.getElementById('addAsgDrawer_<?php echo $cid; ?>').style.display='none'; document.getElementById('editChDrawer_<?php echo $cid; ?>').style.display='none';">
                                    + Tambah Materi
                                </button>
                                <button type="button" class="tatc-btn-outline" style="padding: 6px 12px; font-size: 12px; border-color:#f59e0b; color:#b45309 !important; background:#fffbeb; font-weight:700;" onclick="var d=document.getElementById('addExDrawer_<?php echo $cid; ?>'); d.style.display=(d.style.display==='none'?'block':'none'); document.getElementById('addMatDrawer_<?php echo $cid; ?>').style.display='none'; document.getElementById('addAsgDrawer_<?php echo $cid; ?>').style.display='none'; document.getElementById('editChDrawer_<?php echo $cid; ?>').style.display='none';">
                                    + Buat Quiz / Ujian
                                </button>
                                <button type="button" class="tatc-btn-outline" style="padding: 6px 12px; font-size: 12px; border-color:#8b5cf6; color:#6d28d9 !important; background:#f5f3ff; font-weight:700;" onclick="var d=document.getElementById('addAsgDrawer_<?php echo $cid; ?>'); d.style.display=(d.style.display==='none'?'block':'none'); document.getElementById('addMatDrawer_<?php echo $cid; ?>').style.display='none'; document.getElementById('addExDrawer_<?php echo $cid; ?>').style.display='none'; document.getElementById('editChDrawer_<?php echo $cid; ?>').style.display='none';">
                                    + Buat Assignment
                                </button>
                                <button type="button" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px; color:#1d4ed8 !important; border-color:#bfdbfe; background:#eff6ff;" onclick="var d=document.getElementById('editChDrawer_<?php echo $cid; ?>'); d.style.display=(d.style.display==='none'?'block':'none'); document.getElementById('addMatDrawer_<?php echo $cid; ?>').style.display='none'; document.getElementById('addExDrawer_<?php echo $cid; ?>').style.display='none'; document.getElementById('addAsgDrawer_<?php echo $cid; ?>').style.display='none';">
                                    Edit Bab
                                </button>
                                <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>" style="display:inline;" onsubmit="return confirm('Hapus <?php echo s($ch->chapter_no); ?> beserta seluruh materi & ujian di dalamnya?');">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="delete_chapter">
                                    <input type="hidden" name="chapter_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                    <button type="submit" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px; color:#b91c1c !important; border-color:#fecaca; background:#fef2f2;">
                                        Hapus Bab
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="celoe-chapter-body">
                        <?php if ($can_manage): ?>
                            <!-- DRAWER 1: FORM TAMBAH MATERI KE BAB INI -->
                            <div id="addMatDrawer_<?php echo $cid; ?>" class="celoe-inline-drawer" style="display:none;">
                                <div style="font-weight:800; color:#0f172a; font-size:14px; margin-bottom:10px;">
                                    Tambah Materi Pembelajaran ke Dalam <?php echo s($ch->chapter_no); ?> (<?php echo s($ch->title); ?>)
                                </div>
                                <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>" enctype="multipart/form-data">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="add_material_to_chapter">
                                    <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                    <input type="hidden" name="chapter_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="chapter_no" value="<?php echo s($ch->chapter_no); ?>">
                                    <input type="hidden" name="batch_code" value="<?php echo s($selected_class->batch_code); ?>">
                                    <input type="hidden" name="category_type" value="<?php echo s($selected_class->category_type); ?>">

                                    <div class="tatc-form-grid mb-3">
                                        <div>
                                            <label class="tatc-label">Judul Materi *</label>
                                            <input type="text" name="title" class="tatc-input" placeholder="Contoh: Slide Materi 1 - Pengenalan Sistem" required>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Jenis Bahan Ajar</label>
                                            <select name="file_type" class="tatc-select">
                                                <option value="PDF Training Manual">PDF Training Manual (MTPM)</option>
                                                <option value="Slide Presentasi Kelas">Slide Presentasi Kelas (PPT/PDF)</option>
                                                <option value="Panduan Praktik Hangar (Job Card)">Panduan Praktik Hangar (Job Card)</option>
                                                <option value="Video Pembelajaran">Video Pembelajaran</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Pilih File Materi (PDF/PPT/DOC/Gambar)</label>
                                            <input type="file" name="repo_file" class="tatc-input" accept=".pdf,.ppt,.pptx,.doc,.docx,.mp4,.zip,.png,.jpg,.jpeg">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="tatc-label">Keterangan / Catatan Materi (Opsional)</label>
                                        <input type="text" name="description" class="tatc-input" placeholder="Contoh: Silakan pelajari dokumen ini sebelum sesi kelas dimulai.">
                                    </div>
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;" onclick="document.getElementById('addMatDrawer_<?php echo $cid; ?>').style.display='none';">Batal</button>
                                        <button type="submit" class="tatc-btn-red" style="padding:6px 14px; font-size:12.5px;">Simpan Materi ke <?php echo s($ch->chapter_no); ?></button>
                                    </div>
                                </form>
                            </div>

                            <!-- DRAWER 2: FORM BUAT UJIAN / ASSESSMENT DI BAB INI -->
                            <div id="addExDrawer_<?php echo $cid; ?>" class="celoe-inline-drawer" style="display:none; background:#fffdf5; border-color:#fde68a;">
                                <div style="font-weight:800; color:#78350f; font-size:14px; margin-bottom:10px;">
                                    Buat Ujian / Assessment Baru di Dalam <?php echo s($ch->chapter_no); ?> (<?php echo s($ch->title); ?>)
                                </div>
                                <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="add_exam_to_chapter">
                                    <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                    <input type="hidden" name="chapter_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="chapter_no" value="<?php echo s($ch->chapter_no); ?>">
                                    <input type="hidden" name="batch_code" value="<?php echo s($selected_class->batch_code); ?>">
                                    <input type="hidden" name="category_type" value="<?php echo s($selected_class->category_type); ?>">

                                    <div class="tatc-form-grid mb-3">
                                        <div>
                                            <label class="tatc-label">Judul Ujian / Quiz *</label>
                                            <input type="text" name="title" class="tatc-input" placeholder="Contoh: Quiz Evaluasi <?php echo s($ch->chapter_no); ?>" required>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Kode Ujian (Opsional)</label>
                                            <input type="text" name="exam_code" class="tatc-input" placeholder="Otomatis jika dikosongkan">
                                        </div>
                                        <div>
                                            <label class="tatc-label">Format Quiz / Ujian *</label>
                                            <select name="exam_type" class="tatc-select">
                                                <option value="Quiz Pilihan Ganda (Multiple Choice)">Pilihan Ganda (Multiple Choice)</option>
                                                <option value="Quiz Essay (Uraian)">Essay (Uraian)</option>
                                                <option value="Quiz Campuran (Pilihan Ganda & Essay)">Campuran (Pilihan Ganda &amp; Essay)</option>
                                                <option value="Practical Assessment (Hangar)">Practical Assessment (Hangar)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Tanggal Pelaksanaan</label>
                                            <input type="date" name="exam_date" class="tatc-input" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                                        </div>
                                        <div>
                                            <label class="tatc-label">Durasi (Menit)</label>
                                            <input type="number" name="duration_mins" class="tatc-input" value="60" min="15">
                                        </div>
                                        <div>
                                            <label class="tatc-label">Passing Grade (Standar CASR 147: 75)</label>
                                            <input type="number" name="passing_grade" class="tatc-input" value="75" min="50" max="100">
                                        </div>
                                    </div>
                                    <div style="font-size:12px; color:#92400e; margin-bottom:10px;">
                                        * Setelah Quiz dibuat, klik tombol <strong>"+ Kelola Soal &amp; Gambar"</strong> pada item Quiz tersebut untuk menambahkan butir soal <strong>Pilihan Ganda</strong> atau <strong>Essay</strong> beserta <strong>Lampiran Gambar Soal</strong>.
                                    </div>
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;" onclick="document.getElementById('addExDrawer_<?php echo $cid; ?>').style.display='none';">Batal</button>
                                        <button type="submit" class="tatc-btn-red" style="padding:6px 14px; font-size:12.5px;">Simpan Quiz / Ujian ke <?php echo s($ch->chapter_no); ?></button>
                                    </div>
                                </form>
                            </div>

                            <!-- DRAWER 2B: FORM BUAT ASSIGNMENT (PENUGASAN) LENGKAP DENGAN DEADLINE DI BAB INI -->
                            <div id="addAsgDrawer_<?php echo $cid; ?>" class="celoe-inline-drawer" style="display:none; background:#f5f3ff; border-color:#ddd6fe;">
                                <div style="font-weight:800; color:#5b21b6; font-size:14px; margin-bottom:10px;">
                                    Buat Assignment (Penugasan) Baru Lengkap dengan Deadline di <?php echo s($ch->chapter_no); ?> (<?php echo s($ch->title); ?>)
                                </div>
                                <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>" enctype="multipart/form-data">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="add_assignment_to_chapter">
                                    <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                    <input type="hidden" name="chapter_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="chapter_no" value="<?php echo s($ch->chapter_no); ?>">
                                    <input type="hidden" name="batch_code" value="<?php echo s($selected_class->batch_code); ?>">

                                    <div class="tatc-form-grid mb-3">
                                        <div>
                                            <label class="tatc-label">Judul Assignment / Penugasan *</label>
                                            <input type="text" name="title" class="tatc-input" placeholder="Contoh: Tugas 1 - Analisis Komponen & Troubleshooting" required>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Deadline Pengumpulan (Tanggal &amp; Jam Batas Akhir) *</label>
                                            <input type="datetime-local" name="deadline_at" class="tatc-input" value="<?php echo date('Y-m-d\T23:59', strtotime('+7 days')); ?>" required>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Lampiran File / Gambar Soal Tugas (Opsional)</label>
                                            <input type="file" name="repo_file" class="tatc-input" accept=".pdf,.ppt,.pptx,.doc,.docx,.zip,.png,.jpg,.jpeg">
                                        </div>
                                    </div>
                                    <div class="tatc-form-grid mb-3">
                                        <div style="grid-column: span 2;">
                                            <label class="tatc-label">Instruksi / Deskripsi Penugasan *</label>
                                            <input type="text" name="description" class="tatc-input" placeholder="Contoh: Kerjakan analisis sesuai gambar terlampir dan kumpulkan laporan PDF sebelum batas waktu deadline." required>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Passing Grade Assignment</label>
                                            <input type="number" name="passing_grade" class="tatc-input" value="75" min="50" max="100">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;" onclick="document.getElementById('addAsgDrawer_<?php echo $cid; ?>').style.display='none';">Batal</button>
                                        <button type="submit" class="tatc-btn-red" style="padding:6px 14px; font-size:12.5px; background:#6d28d9;">Simpan Assignment ke <?php echo s($ch->chapter_no); ?></button>
                                    </div>
                                </form>
                            </div>

                            <!-- DRAWER 3: FORM EDIT BAB -->
                            <div id="editChDrawer_<?php echo $cid; ?>" class="celoe-inline-drawer" style="display:none; background:#eff6ff; border-color:#bfdbfe;">
                                <div style="font-weight:800; color:#1e3a8a; font-size:14px; margin-bottom:10px;">
                                    Edit Informasi <?php echo s($ch->chapter_no); ?>
                                </div>
                                <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="edit_chapter">
                                    <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                    <input type="hidden" name="chapter_id" value="<?php echo $cid; ?>">

                                    <div class="tatc-form-grid mb-3">
                                        <div>
                                            <label class="tatc-label">Urutan Bab *</label>
                                            <select name="chapter_no" class="tatc-select">
                                                <?php for ($bi = 1; $bi <= 12; $bi++): ?>
                                                    <?php $bl = 'Bab ' . $bi; ?>
                                                    <option value="<?php echo $bl; ?>" <?php echo (strcasecmp($ch->chapter_no, $bl) === 0) ? 'selected' : ''; ?>><?php echo $bl; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Judul Topik Bab *</label>
                                            <input type="text" name="title" class="tatc-input" value="<?php echo s($ch->title); ?>" required>
                                        </div>
                                        <div>
                                            <label class="tatc-label">Deskripsi Singkat Bab</label>
                                            <input type="text" name="description" class="tatc-input" value="<?php echo s((string)$ch->description); ?>">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;" onclick="document.getElementById('editChDrawer_<?php echo $cid; ?>').style.display='none';">Batal</button>
                                        <button type="submit" class="tatc-btn-red" style="padding:6px 14px; font-size:12.5px;">Simpan Perubahan Bab</button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- ISI BAB: DAFTAR MATERI, QUIZ/UJIAN, & ASSIGNMENT DI DALAM BAB INI -->
                        <?php if (empty($ch_materials) && empty($ch_exams) && empty($ch_assignments)): ?>
                            <div style="text-align:center; padding: 18px 14px; border: 1px dashed #cbd5e1; border-radius: 10px; color: #64748b; font-size: 13px;">
                                Belum ada materi, quiz/ujian, atau assignment di dalam <strong><?php echo s($ch->chapter_no); ?></strong>.
                                <?php if ($can_manage): ?>
                                    Klik tombol <strong>"+ Tambah Materi"</strong>, <strong>"+ Buat Quiz / Ujian"</strong>, atau <strong>"+ Buat Assignment"</strong> di pojok kanan atas <?php echo s($ch->chapter_no); ?> untuk menambahkan konten.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <!-- ITEM MATERI -->
                            <?php foreach ($ch_materials as $item): ?>
                                <div class="celoe-item-row">
                                    <div style="max-width: 680px;">
                                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                            <span class="tatc-doc-pill" style="margin:0; background:#dcfce7; color:#15803d; font-weight:800;">MATERI</span>
                                            <strong style="font-size: 14px; color: #0f172a;"><?php echo s($item->title); ?></strong>
                                            <span class="tatc-doc-pill" style="margin:0;"><?php echo s($item->file_type); ?></span>
                                        </div>
                                        <?php if (!empty($item->description)): ?>
                                            <div style="font-size: 12.5px; color: #475569; margin-top: 3px;">
                                                <?php echo s($item->description); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size: 11.5px; color: #94a3b8; margin-top: 4px;">
                                            Diunggah oleh: <strong><?php echo s($item->uploader_name); ?></strong> &bull; <?php echo s($item->file_size); ?>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                        <?php if (!empty($item->file_path)): ?>
                                            <a href="/amto/download.php?type=repo&amp;id=<?php echo (int)$item->id; ?>" target="_blank" class="tatc-btn-red" style="padding: 6px 12px; font-size: 12px;">
                                                <?php echo $can_manage ? 'Lihat File' : 'Buka / Unduh Materi'; ?>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($can_manage): ?>
                                            <button type="button" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px; color:#1d4ed8 !important; border-color:#bfdbfe; background:#eff6ff;" onclick="var em=document.getElementById('editMat_<?php echo (int)$item->id; ?>'); em.style.display=(em.style.display==='none'?'block':'none');">
                                                Edit
                                            </button>
                                            <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>" style="display:inline;" onsubmit="return confirm('Hapus materi ini dari <?php echo s($ch->chapter_no); ?>?');">
                                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                <input type="hidden" name="action" value="delete_module">
                                                <input type="hidden" name="mod_id" value="<?php echo (int)$item->id; ?>">
                                                <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                                <button type="submit" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px; color:#b91c1c !important; border-color:#fecaca; background:#fef2f2;">
                                                    Hapus
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($can_manage): ?>
                                    <div id="editMat_<?php echo (int)$item->id; ?>" class="celoe-inline-drawer" style="display:none; background:#eff6ff; border-color:#bfdbfe;">
                                        <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>" enctype="multipart/form-data">
                                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                            <input type="hidden" name="action" value="edit_material">
                                            <input type="hidden" name="mod_id" value="<?php echo (int)$item->id; ?>">
                                            <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                            <div class="tatc-form-grid mb-2">
                                                <div>
                                                    <label class="tatc-label">Judul Materi *</label>
                                                    <input type="text" name="title" class="tatc-input" value="<?php echo s($item->title); ?>" required>
                                                </div>
                                                <div>
                                                    <label class="tatc-label">Jenis Bahan Ajar</label>
                                                    <input type="text" name="file_type" class="tatc-input" value="<?php echo s($item->file_type); ?>">
                                                </div>
                                                <div>
                                                    <label class="tatc-label">Ganti File (Opsional)</label>
                                                    <input type="file" name="repo_file" class="tatc-input">
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <label class="tatc-label">Keterangan Materi</label>
                                                <input type="text" name="description" class="tatc-input" value="<?php echo s((string)$item->description); ?>">
                                            </div>
                                            <div class="d-flex justify-content-end gap-2">
                                                <button type="button" class="tatc-btn-outline" style="padding:5px 10px; font-size:12px;" onclick="document.getElementById('editMat_<?php echo (int)$item->id; ?>').style.display='none';">Batal</button>
                                                <button type="submit" class="tatc-btn-red" style="padding:5px 12px; font-size:12px;">Simpan Perubahan Materi</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <!-- ITEM QUIZ / UJIAN DI DALAM BAB INI -->
                            <?php foreach ($ch_exams as $cex): ?>
                                <?php
                                $q_count = $DB->count_records('amto_exam_questions', ['exam_id' => $cex->id]);
                                ?>
                                <div class="celoe-item-row" style="background:#fffdf5; border-color:#fde68a;">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                            <span class="tatc-doc-pill" style="margin:0; background:#fef3c7; color:#b45309; font-weight:800;">QUIZ / UJIAN</span>
                                            <span class="tatc-doc-pill" style="margin:0; font-weight:700;"><?php echo s($cex->exam_code); ?></span>
                                            <strong style="font-size: 14px; color: #0f172a;"><?php echo s($cex->title); ?></strong>
                                            <span class="tatc-doc-pill" style="margin:0;"><?php echo s($cex->exam_type); ?></span>
                                            <span class="tatc-doc-pill" style="margin:0; background:#e0f2fe; color:#0369a1; font-weight:700;"><?php echo $q_count; ?> Butir Soal</span>
                                        </div>
                                        <div style="font-size: 12px; color: #64748b;">
                                            Passing Grade: <strong><?php echo (int)$cex->passing_grade; ?>/100</strong> &bull;
                                            Durasi: <strong><?php echo (int)$cex->duration_mins; ?> Menit</strong> &bull;
                                            Jadwal: <strong><?php echo s($cex->exam_date); ?></strong> &bull;
                                            Assessor: <strong><?php echo s($cex->assessor_name); ?></strong>
                                        </div>
                                    </div>

                                    <?php if ($can_manage): ?>
                                        <div class="d-flex flex-wrap gap-1 align-items-center">
                                            <a href="/amto/ujian_logbook.php?tab=ujian&amp;manage_questions=<?php echo (int)$cex->id; ?>" class="tatc-btn-red" style="padding: 6px 12px; font-size: 12px;">
                                                + Kelola Soal &amp; Gambar (<?php echo $q_count; ?>)
                                            </a>
                                            <a href="/amto/ujian_logbook.php?tab=hasil" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px;">
                                                Cek Nilai
                                            </a>
                                            <a href="/amto/ujian_logbook.php?tab=ujian&amp;edit_exam=<?php echo (int)$cex->id; ?>" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px; color:#1d4ed8 !important; border-color:#bfdbfe; background:#eff6ff;">
                                                Edit Quiz
                                            </a>
                                            <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>" style="display:inline;" onsubmit="return confirm('Hapus ujian ini dari <?php echo s($ch->chapter_no); ?>?');">
                                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                <input type="hidden" name="action" value="delete_exam">
                                                <input type="hidden" name="exam_id" value="<?php echo (int)$cex->id; ?>">
                                                <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                                <button type="submit" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px; color:#b91c1c !important; border-color:#fecaca; background:#fef2f2;">
                                                    Hapus
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <a href="/amto/ujian_logbook.php?tab=ujian&amp;take_exam=<?php echo (int)$cex->id; ?>" class="tatc-btn-red" style="padding: 7px 14px; font-size: 12.5px;">
                                            Kerjakan Quiz / Ujian &rarr;
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <!-- ITEM ASSIGNMENT / PENUGASAN LENGKAP DENGAN DEADLINE DI DALAM BAB INI -->
                            <?php foreach ($ch_assignments as $asg): ?>
                                <?php
                                $dl_ts = strtotime($asg->deadline_at);
                                $is_expired = ($dl_ts && time() > $dl_ts);
                                $sub_count = $DB->count_records('amto_assignment_submissions', ['assignment_id' => $asg->id]);
                                ?>
                                <div class="celoe-item-row" style="background:#faf5ff; border-color:#ddd6fe;">
                                    <div style="max-width: 680px;">
                                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                            <span class="tatc-doc-pill" style="margin:0; background:#ede9fe; color:#6d28d9; font-weight:800;">ASSIGNMENT / PENUGASAN</span>
                                            <strong style="font-size: 14px; color: #0f172a;"><?php echo s($asg->title); ?></strong>
                                            <span class="tatc-doc-pill" style="margin:0; <?php echo $is_expired ? 'background:#fee2e2; color:#b91c1c;' : 'background:#dcfce7; color:#15803d;'; ?> font-weight:700;">
                                                Deadline: <?php echo s($asg->deadline_at); ?> WIB <?php echo $is_expired ? '(Melewati Deadline)' : '(Masih Dibuka)'; ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($asg->description)): ?>
                                            <div style="font-size: 12.5px; color: #475569; margin-top: 3px;">
                                                <?php echo s($asg->description); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">
                                            Passing Grade: <strong><?php echo (int)$asg->passing_grade; ?>/100</strong> &bull;
                                            Dibuat oleh: <strong><?php echo s($asg->created_by); ?></strong> &bull;
                                            Terkumpul: <strong><?php echo $sub_count; ?> Peserta</strong>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                        <?php if (!empty($asg->attachment_path)): ?>
                                            <a href="/amto/download.php?type=assign&amp;id=<?php echo (int)$asg->id; ?>" target="_blank" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px;">
                                                Unduh Lampiran Soal
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($can_manage): ?>
                                            <a href="/amto/ujian_logbook.php?tab=assignment&amp;view_assign=<?php echo (int)$asg->id; ?>" class="tatc-btn-red" style="padding: 6px 12px; font-size: 12px; background:#6d28d9;">
                                                Cek Pengumpulan (<?php echo $sub_count; ?>)
                                            </a>
                                            <button type="button" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px; color:#1d4ed8 !important; border-color:#bfdbfe; background:#eff6ff;" onclick="var ea=document.getElementById('editAsg_<?php echo (int)$asg->id; ?>'); ea.style.display=(ea.style.display==='none'?'block':'none');">
                                                Edit Deadline
                                            </button>
                                            <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>" style="display:inline;" onsubmit="return confirm('Hapus assignment ini dari <?php echo s($ch->chapter_no); ?>?');">
                                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                <input type="hidden" name="action" value="delete_assignment">
                                                <input type="hidden" name="assign_id" value="<?php echo (int)$asg->id; ?>">
                                                <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                                <button type="submit" class="tatc-btn-outline" style="padding: 6px 10px; font-size: 12px; color:#b91c1c !important; border-color:#fecaca; background:#fef2f2;">
                                                    Hapus
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <a href="/amto/ujian_logbook.php?tab=assignment&amp;submit_assign=<?php echo (int)$asg->id; ?>" class="tatc-btn-red" style="padding: 7px 14px; font-size: 12.5px; background:#6d28d9;">
                                                Kerjakan &amp; Kumpulkan Tugas &rarr;
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($can_manage): ?>
                                    <div id="editAsg_<?php echo (int)$asg->id; ?>" class="celoe-inline-drawer" style="display:none; background:#f5f3ff; border-color:#ddd6fe;">
                                        <form method="post" action="/amto/repository.php?class_id=<?php echo (int)$selected_class->id; ?>" enctype="multipart/form-data">
                                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                            <input type="hidden" name="action" value="edit_assignment">
                                            <input type="hidden" name="assign_id" value="<?php echo (int)$asg->id; ?>">
                                            <input type="hidden" name="target_class_id" value="<?php echo (int)$selected_class->id; ?>">
                                            <div class="tatc-form-grid mb-2">
                                                <div>
                                                    <label class="tatc-label">Judul Assignment *</label>
                                                    <input type="text" name="title" class="tatc-input" value="<?php echo s($asg->title); ?>" required>
                                                </div>
                                                <div>
                                                    <label class="tatc-label">Setting Deadline (Tanggal &amp; Jam) *</label>
                                                    <input type="datetime-local" name="deadline_at" class="tatc-input" value="<?php echo s(str_replace(' ', 'T', substr($asg->deadline_at, 0, 16))); ?>" required>
                                                </div>
                                                <div>
                                                    <label class="tatc-label">Ganti Lampiran File/Gambar (Opsional)</label>
                                                    <input type="file" name="repo_file" class="tatc-input">
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <label class="tatc-label">Instruksi Penugasan</label>
                                                <input type="text" name="description" class="tatc-input" value="<?php echo s((string)$asg->description); ?>">
                                            </div>
                                            <div class="d-flex justify-content-end gap-2">
                                                <button type="button" class="tatc-btn-outline" style="padding:5px 10px; font-size:12px;" onclick="document.getElementById('editAsg_<?php echo (int)$asg->id; ?>').style.display='none';">Batal</button>
                                                <button type="submit" class="tatc-btn-red" style="padding:5px 12px; font-size:12px; background:#6d28d9;">Simpan Perubahan &amp; Deadline</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- LEVEL 1: TAMPILAN KOTAK-KOTAK KELAS SAYA (COURSE CARDS ALA CeLOE LMS TELKOM UNIVERSITY) -->
    <div class="tatc-hero">
        <div>
            <div class="tatc-hero-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span>CASR Part 147 &bull; My Courses &amp; Training Syllabus</span>
            </div>
            <h1>
                <?php
                if ($role === 'admin') {
                    echo 'Daftar Kelas & Manajemen Silabus Materi AMTO';
                } else if ($role === 'instructor') {
                    echo 'Kelas Mengajar & Silabus Materi Instruktur';
                } else {
                    echo 'Kelas & Materi Saya';
                }
                ?>
            </h1>
            <p>
                Pilih salah satu kotak kelas pelatihan di bawah ini untuk melihat detail <strong>Silabus Pembelajaran (Bab 1, Bab 2, dst.)</strong>, mengunduh materi kelas, serta melihat jadwal Assessment / Kuis pada kelas tersebut.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($can_manage): ?>
                <a href="/amto/jadwal.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                    <span>+ Buat Kelas / Jadwal Baru</span>
                </a>
            <?php else: ?>
                <a href="/amto/jadwal.php" class="tatc-btn-outline" style="background: rgba(255,255,255,0.15); color:#fff !important; border-color: rgba(255,255,255,0.3);">
                    <span>Lihat Jadwal Pelatihan</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($notice_msg !== ''): ?>
        <div class="alert alert-<?php echo $notice_type; ?> mb-4" style="border-radius:12px; font-size:14px;">
            <?php echo $notice_msg; ?>
        </div>
    <?php endif; ?>

    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Kelas Pelatihan Aktif</h2>
                <p class="tatc-panel-sub">Klik pada kotak kelas untuk membuka halaman Silabus (Bab 1, Bab 2, Materi &amp; Assessment).</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="/amto/repository.php?cat=all" class="<?php echo ($cat_filter === 'all') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Semua Kelas (<?php echo count($schedules); ?>)</a>
                <a href="/amto/repository.php?cat=Basic License" class="<?php echo ($cat_filter === 'Basic License') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Basic License</a>
                <a href="/amto/repository.php?cat=Type Rating" class="<?php echo ($cat_filter === 'Type Rating') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Type Rating</a>
                <a href="/amto/repository.php?cat=Mandatory" class="<?php echo ($cat_filter === 'Mandatory') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Mandatory</a>
            </div>
        </div>

        <?php
        $filtered_classes = [];
        foreach ($schedules as $sc) {
            if ($cat_filter !== 'all' && stripos($sc->category_type, $cat_filter) === false) {
                continue;
            }
            $filtered_classes[] = $sc;
        }
        ?>

        <?php if (empty($filtered_classes)): ?>
            <div class="text-center py-5">
                <div style="font-weight: 800; color: #0f172a; font-size: 16px; margin-bottom: 6px;">
                    Belum Ada Kelas Pelatihan yang Tersedia
                </div>
                <p class="text-muted mb-0" style="font-size: 13.5px;">
                    <?php if ($can_manage): ?>
                        Silakan buat jadwal/kelas terlebih dahulu di menu <strong>Program &amp; Jadwal</strong> agar kotak kelas muncul di sini.
                    <?php else: ?>
                        Saat ini belum ada kelas pelatihan aktif yang dibuka oleh Admin AMTO.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="celoe-course-grid">
                <?php foreach ($filtered_classes as $cls): ?>
                    <?php
                    $chap_total = 0;
                    foreach ($all_chapters as $ch) {
                        if ((int)$ch->schedule_id === (int)$cls->id ||
                            strcasecmp(trim($ch->batch_code), trim($cls->batch_code)) === 0) {
                            $chap_total++;
                        }
                    }
                    $mod_count = 0;
                    foreach ($all_modules as $m) {
                        if (strcasecmp(trim($m->batch_code), trim($cls->batch_code)) === 0 ||
                            strcasecmp(trim($m->batch_code), 'Semua Batch') === 0) {
                            $mod_count++;
                        }
                    }
                    $assess_count = 0;
                    foreach ($all_exams as $ex) {
                        if (strcasecmp(trim($ex->batch_code), trim($cls->batch_code)) === 0 ||
                            stripos($cls->category_type, $ex->category_type) !== false ||
                            stripos($ex->category_type, $cls->category_type) !== false) {
                            $assess_count++;
                        }
                    }
                    ?>
                    <a href="/amto/repository.php?class_id=<?php echo (int)$cls->id; ?>" class="celoe-course-card">
                        <div>
                            <div class="celoe-card-banner">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="celoe-card-code"><?php echo s($cls->category_type); ?> &bull; <?php echo s($cls->batch_code); ?></span>
                                    <span class="tatc-badge tatc-badge-approved" style="font-size: 10.5px; padding: 3px 9px;">Kelas Aktif</span>
                                </div>
                                <h3 class="celoe-card-title"><?php echo s($cls->program_name); ?></h3>
                                <div class="celoe-card-mode"><?php echo s($cls->training_mode); ?></div>
                            </div>

                            <div class="celoe-card-body">
                                <div class="celoe-meta-list">
                                    <div class="celoe-meta-item">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#b91c1c" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        <span>Instruktur: <strong><?php echo s($cls->instructor_name); ?></strong></span>
                                    </div>
                                    <div class="celoe-meta-item">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#b91c1c" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <span>Ruangan / Hangar: <strong><?php echo s($cls->room_location); ?></strong></span>
                                    </div>
                                    <div class="celoe-meta-item">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#b91c1c" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <span>Periode: <strong><?php echo s($cls->start_date); ?> s/d <?php echo s($cls->end_date); ?></strong></span>
                                    </div>
                                </div>

                                <div class="celoe-syllabus-pills">
                                    <span class="tatc-doc-pill" style="margin:0; background:#f1f5f9; color:#334155; font-weight:700;">
                                        <?php echo $chap_total; ?> Bab Silabus
                                    </span>
                                    <span class="tatc-doc-pill" style="margin:0; background:#f1f5f9; color:#334155; font-weight:700;">
                                        <?php echo $mod_count; ?> Materi Modul
                                    </span>
                                    <span class="tatc-doc-pill" style="margin:0; <?php echo $assess_count > 0 ? 'background:#fef3c7; color:#b45309; font-weight:700;' : 'background:#f1f5f9; color:#64748b;'; ?>">
                                        <?php echo $assess_count > 0 ? $assess_count . ' Assessment Aktif' : 'Belum Ada Assessment'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="celoe-card-footer">
                            <span style="font-size: 12px; color: #64748b; font-weight: 600;">
                                <?php echo s($cls->schedule_time); ?>
                            </span>
                            <span style="font-size: 13px; color: #b91c1c; font-weight: 800;">
                                <?php echo $can_manage ? 'Kelola Silabus &rarr;' : 'Buka Silabus &rarr;'; ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>

<?php
echo $OUTPUT->footer();
