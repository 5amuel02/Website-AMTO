<?php
/**
 * AMTO Module: Ujian Kompetensi (CBT), Practical Logbook Hangar & Hasil Assessment CASR Part 147.
 * Role-Aware for Peserta (R01), Instruktur (R02), Assessor (R03), Training Manager (R04), Cert Officer (R05), QA (R06), Admin (R07), Manajemen (R08).
 */
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

amto_auto_sync_from_tatc_cookie();
require_login();
amto_ensure_tables();

global $DB, $USER, $PAGE, $OUTPUT;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/amto/ujian_logbook.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('TATC Portal – Ujian Kompetensi, Practical Logbook & Assessment AMTO');
$PAGE->set_heading('Ujian Kompetensi, Practical Logbook & Assessment AMTO');

$role = amto_current_role();
$rbac_role = amto_current_rbac_role();
$role_info = amto_get_detailed_role();

$is_student = ($rbac_role === 'student');
$can_manage_exam = in_array($rbac_role, ['admin', 'trainingmanager', 'assessor', 'instructor'], true);
$can_verify_logbook = in_array($rbac_role, ['admin', 'trainingmanager', 'instructor', 'assessor'], true);
$is_readonly_audit = in_array($rbac_role, ['qa', 'management', 'certofficer', 'adminit'], true);

$active_tab = optional_param('tab', 'ujian', PARAM_ALPHA);
if (!in_array($active_tab, ['ujian', 'logbook', 'hasil'], true)) {
    $active_tab = 'ujian';
}
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$msg = trim(optional_param('msg', '', PARAM_TEXT));
$take_exam_id = optional_param('take_exam', 0, PARAM_INT);
$edit_exam_id = optional_param('edit_exam', 0, PARAM_INT);

// Handle POST Actions with CSRF sesskey protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    // 1. Student submits Online CBT Simulation Exam
    if ($action === 'submit_cbt') {
        $exam_id = required_param('exam_id', PARAM_INT);
        $exam = $DB->get_record('amto_exams', ['id' => $exam_id]);
        if ($exam) {
            $correct_answers = [
                'q1' => 'B', // Passing grade CASR Part 147 = 75%
                'q2' => 'A', // AMTO Certificate Number = 147D-19
                'q3' => 'C', // EWIS = Electrical Wiring Interconnection System
                'q4' => 'B', // Minimum attendance = 80%
                'q5' => 'A', // FOD = Foreign Object Damage / Debris
            ];
            $correct_count = 0;
            foreach ($correct_answers as $qk => $expected) {
                $ans = optional_param($qk, '', PARAM_ALPHA);
                if (strtoupper($ans) === $expected) {
                    $correct_count++;
                }
            }
            $score = $correct_count * 20;
            $status = ($score >= (int)$exam->passing_grade) ? 'LULUS / KOMPETEN' : 'BELUM LULUS (RE-EXAM)';

            $res = new stdClass();
            $res->exam_id       = (int)$exam->id;
            $res->exam_code     = $exam->exam_code;
            $res->exam_title    = $exam->title;
            $res->userid        = (int)$USER->id;
            $res->username      = $USER->username;
            $res->fullname      = fullname($USER);
            $res->score         = $score;
            $res->passing_grade = (int)$exam->passing_grade;
            $res->status        = $status;
            $res->notes         = "CBT Online selesai dikerjakan ({$correct_count}/5 jawaban benar).";
            $res->assessed_by   = $exam->assessor_name . ' (Auto-Graded CBT Engine)';
            $res->timecreated   = time();
            $new_id = $DB->insert_record('amto_exam_results', $res);

            amto_log_audit_event('CBT_EXAM_SUBMITTED', 'amto_exam_results', (int)$new_id, "exam={$exam->exam_code}, score={$score}, status={$status}", "Participant {$USER->username} completed CBT exam {$exam->title}");
            header('Location: /amto/ujian_logbook.php?tab=hasil&msg=' . urlencode("Ujian CBT {$exam->exam_code} berhasil dikirim! Nilai Anda: {$score}/100 ({$status})."));
            exit;
        }
    }

    // 2. Student submits a new Practical Logbook Hangar entry
    if ($action === 'add_logbook' && !$is_readonly_audit) {
        $batch_code      = trim(optional_param('batch_code', 'BATCH-AMTO-2601', PARAM_TEXT));
        $practice_date   = trim(optional_param('practice_date', date('Y-m-d'), PARAM_TEXT));
        $ata_chapter     = trim(optional_param('ata_chapter', '', PARAM_TEXT));
        $task_title      = trim(optional_param('task_title', '', PARAM_TEXT));
        $hangar_location = trim(optional_param('hangar_location', 'Hangar B-01 TATC', PARAM_TEXT));
        $duration_hours  = trim(optional_param('duration_hours', '4.0 Jam', PARAM_TEXT));
        $tools_used      = trim(optional_param('tools_used', '', PARAM_TEXT));
        $description     = trim(optional_param('description', '', PARAM_TEXT));

        if ($ata_chapter !== '' && $task_title !== '') {
            $last = $DB->get_records('amto_logbooks', null, 'id DESC', 'id', 0, 1);
            $next_no = !empty($last) ? (reset($last)->id + 1) : 1;
            $log_no = sprintf('LOG-AMTO-2026-%04d', $next_no);

            $rec = new stdClass();
            $rec->log_no           = $log_no;
            $rec->userid           = (int)$USER->id;
            $rec->username         = $USER->username;
            $rec->fullname         = fullname($USER);
            $rec->batch_code       = $batch_code;
            $rec->practice_date    = $practice_date;
            $rec->ata_chapter      = $ata_chapter;
            $rec->task_title       = $task_title;
            $rec->hangar_location  = $hangar_location;
            $rec->duration_hours   = $duration_hours;
            $rec->tools_used       = $tools_used ?: 'Standard Aircraft Maintenance ToolKit';
            $rec->description      = $description;
            $rec->instructor_notes = 'Menunggu verifikasi & sign-off Instruktur / Assessor.';
            $rec->verified_by      = '';
            $rec->status           = 'Pending Verification';
            $rec->timecreated      = time();
            $lid = $DB->insert_record('amto_logbooks', $rec);

            amto_log_audit_event('LOGBOOK_CREATE', 'amto_logbooks', (int)$lid, "log_no={$log_no}, ata={$ata_chapter}", "Created practical hangar logbook entry {$task_title}");
            header('Location: /amto/ujian_logbook.php?tab=logbook&msg=' . urlencode("Entri Practical Logbook {$log_no} ({$ata_chapter}) berhasil disimpan dan menunggu verifikasi Instruktur."));
            exit;
        }
    }

    // 3. Instructor / Assessor verifies or requests revision on a Logbook entry
    if ($action === 'verify_logbook' && $can_verify_logbook) {
        $log_id = required_param('log_id', PARAM_INT);
        $decision = required_param('decision', PARAM_TEXT);
        $notes = trim(optional_param('instructor_notes', '', PARAM_TEXT));
        $log = $DB->get_record('amto_logbooks', ['id' => $log_id]);
        if ($log) {
            $log->status = ($decision === 'approve') ? 'Verified / Kompeten' : 'Perlu Revisi';
            $log->instructor_notes = $notes !== '' ? $notes : (($decision === 'approve') ? 'Pekerjaan praktik sesuai prosedur AMM & standar keselamatan hangar.' : 'Mohon lengkapi detail referensi AMM dan alat ukur yang digunakan.');
            $log->verified_by = fullname($USER) . ' (' . $role_info['code'] . ')';
            $DB->update_record('amto_logbooks', $log);

            amto_log_audit_event('LOGBOOK_VERIFY', 'amto_logbooks', (int)$log_id, "log_no={$log->log_no}, status={$log->status}", "Verified by {$log->verified_by}");
            header('Location: /amto/ujian_logbook.php?tab=logbook&msg=' . urlencode("Status Logbook {$log->log_no} ({$log->fullname}) berhasil diperbarui menjadi {$log->status}."));
            exit;
        }
    }

    // 4. Assessor / Instructor / Training Manager adds a new Exam or manual Assessment Grade
    if ($action === 'add_exam' && $can_manage_exam) {
        $e = new stdClass();
        $e->exam_code     = strtoupper(trim(optional_param('exam_code', '', PARAM_TEXT)));
        $e->title         = trim(optional_param('title', '', PARAM_TEXT));
        $e->category_type = trim(optional_param('category_type', 'Basic License', PARAM_TEXT));
        $e->batch_code    = trim(optional_param('batch_code', 'BATCH-AMTO-2601', PARAM_TEXT));
        $e->exam_type     = trim(optional_param('exam_type', 'Written CBT (Multiple Choice)', PARAM_TEXT));
        $e->passing_grade = max(50, min(100, optional_param('passing_grade', 75, PARAM_INT)));
        $e->duration_mins = max(15, optional_param('duration_mins', 60, PARAM_INT));
        $e->exam_date     = trim(optional_param('exam_date', date('Y-m-d'), PARAM_TEXT));
        $e->assessor_name = trim(optional_param('assessor_name', fullname($USER), PARAM_TEXT));
        $e->status        = 'Active';
        $e->timecreated   = time();

        if ($e->exam_code !== '' && $e->title !== '') {
            $eid = $DB->insert_record('amto_exams', $e);
            if (function_exists('amto_sync_cross_db')) {
                amto_sync_cross_db(true);
            }
            amto_log_audit_event('EXAM_CREATE', 'amto_exams', (int)$eid, "exam_code={$e->exam_code}", "Created exam {$e->title}");
            header('Location: /amto/ujian_logbook.php?tab=ujian&msg=' . urlencode("Jadwal & Modul Ujian {$e->exam_code} ({$e->title}) berhasil ditambahkan dan disinkronkan ke akun Peserta."));
            exit;
        }
    }

    if ($action === 'edit_exam' && $can_manage_exam) {
        $exam_id = required_param('exam_id', PARAM_INT);
        $e = $DB->get_record('amto_exams', ['id' => $exam_id]);
        if ($e) {
            $e->exam_code     = strtoupper(trim(optional_param('exam_code', $e->exam_code, PARAM_TEXT)));
            $e->title         = trim(optional_param('title', $e->title, PARAM_TEXT));
            $e->batch_code    = trim(optional_param('batch_code', $e->batch_code, PARAM_TEXT));
            $e->exam_type     = trim(optional_param('exam_type', $e->exam_type, PARAM_TEXT));
            $e->passing_grade = max(50, min(100, optional_param('passing_grade', (int)$e->passing_grade, PARAM_INT)));
            $e->duration_mins = max(15, optional_param('duration_mins', (int)$e->duration_mins, PARAM_INT));
            $e->exam_date     = trim(optional_param('exam_date', $e->exam_date, PARAM_TEXT));
            $e->assessor_name = trim(optional_param('assessor_name', $e->assessor_name, PARAM_TEXT));
            $DB->update_record('amto_exams', $e);
            amto_log_audit_event('EXAM_UPDATE', 'amto_exams', (int)$exam_id, "exam_code={$e->exam_code}", "Updated exam {$e->title}");
            header('Location: /amto/ujian_logbook.php?tab=ujian&msg=' . urlencode("Data Ujian [{$e->exam_code}] {$e->title} berhasil diperbarui."));
            exit;
        }
    }

    if ($action === 'delete_exam' && $can_manage_exam) {
        $exam_id = required_param('exam_id', PARAM_INT);
        $e = $DB->get_record('amto_exams', ['id' => $exam_id]);
        if ($e) {
            $e->status = 'Voided';
            $DB->update_record('amto_exams', $e);
            $DB->delete_records('amto_exam_results', ['exam_id' => $exam_id]);
            try {
                $DB->execute("DELETE FROM tatc_web.exams WHERE code = ?", [$e->exam_code]);
            } catch (Throwable $err) {
            }
            amto_log_audit_event('EXAM_VOID', 'amto_exams', (int)$exam_id, 'status=Voided', "Exam [{$e->exam_code}] voided by " . fullname($USER));
            header('Location: /amto/ujian_logbook.php?tab=ujian&msg=' . urlencode("Ujian [{$e->exam_code}] {$e->title} beserta nilai terkait telah dihapus."));
            exit;
        }
    }

    if ($action === 'input_grade' && $can_manage_exam) {
        $exam_id = required_param('exam_id', PARAM_INT);
        $student_username = trim(optional_param('student_username', 'peserta', PARAM_TEXT));
        $score = max(0, min(100, optional_param('score', 80, PARAM_INT)));
        $notes = trim(optional_param('notes', '', PARAM_TEXT));

        $exam = $DB->get_record('amto_exams', ['id' => $exam_id]);
        $suser = $DB->get_record('user', ['username' => $student_username, 'deleted' => 0]);
        if ($exam) {
            $status = ($score >= (int)$exam->passing_grade) ? 'LULUS / KOMPETEN' : 'BELUM LULUS (RE-EXAM)';
            $res = new stdClass();
            $res->exam_id       = (int)$exam->id;
            $res->exam_code     = $exam->exam_code;
            $res->exam_title    = $exam->title;
            $res->userid        = $suser ? (int)$suser->id : 0;
            $res->username      = $suser ? $suser->username : $student_username;
            $res->fullname      = $suser ? fullname($suser) : ucfirst($student_username);
            $res->score         = $score;
            $res->passing_grade = (int)$exam->passing_grade;
            $res->status        = $status;
            $res->notes         = $notes !== '' ? $notes : 'Nilai dievaluasi dan diverifikasi oleh Examiner / Assessor.';
            $res->assessed_by   = fullname($USER) . ' (' . $role_info['code'] . ')';
            $res->timecreated   = time();
            $rid = $DB->insert_record('amto_exam_results', $res);

            amto_log_audit_event('EXAM_GRADE_INPUT', 'amto_exam_results', (int)$rid, "user={$res->username}, exam={$exam->exam_code}, score={$score}", "Assessed by {$res->assessed_by}");
            header('Location: /amto/ujian_logbook.php?tab=hasil&msg=' . urlencode("Nilai Assessment untuk {$res->fullname} pada ujian {$exam->exam_code} ({$score}/100 - {$status}) berhasil disimpan."));
            exit;
        }
    }
}

// Fetch Data (Scoped per SCP-01 for Student vs Full for Staff)
$raw_exams = $DB->get_records('amto_exams', null, 'exam_date ASC, id ASC');
$exams = [];
foreach ($raw_exams as $re) {
    if (in_array(strtolower((string)$re->status), ['void', 'voided'], true)) {
        continue;
    }
    $exams[$re->id] = $re;
}
$raw_schedules = $DB->get_records('amto_schedules', null, 'start_date ASC');
$schedules = [];
foreach ($raw_schedules as $rs) {
    if (in_array(strtolower((string)$rs->status), ['void', 'voided'], true)) {
        continue;
    }
    $schedules[$rs->id] = $rs;
}
$editing_exam = ($edit_exam_id > 0 && isset($exams[$edit_exam_id])) ? $exams[$edit_exam_id] : null;

if ($is_student) {
    $raw_exam_results = $DB->get_records_select(
        'amto_exam_results',
        'userid = :uid OR LOWER(username) = :uname',
        ['uid' => $USER->id, 'uname' => strtolower($USER->username)],
        'timecreated DESC'
    );
    $logbooks = $DB->get_records_select(
        'amto_logbooks',
        'userid = :uid OR LOWER(username) = :uname',
        ['uid' => $USER->id, 'uname' => strtolower($USER->username)],
        'practice_date DESC, id DESC'
    );
} else {
    $raw_exam_results = $DB->get_records('amto_exam_results', null, 'timecreated DESC');
    $logbooks = $DB->get_records('amto_logbooks', null, 'practice_date DESC, id DESC');
}

// Only include exam results belonging to active (non-voided) exams
$exam_results = [];
foreach ($raw_exam_results as $rer) {
    if (!isset($exams[$rer->exam_id])) {
        continue;
    }
    $exam_results[$rer->id] = $rer;
}

$verified_logs = 0;
$pending_logs = 0;
foreach ($logbooks as $lb) {
    if (stripos($lb->status, 'Verified') !== false) {
        $verified_logs++;
    } else {
        $pending_logs++;
    }
}

$passed_exams = 0;
foreach ($exam_results as $er) {
    if ($er->score >= $er->passing_grade) {
        $passed_exams++;
    }
}

$selected_cbt_exam = ($take_exam_id > 0) ? $DB->get_record('amto_exams', ['id' => $take_exam_id]) : null;

echo $OUTPUT->header();
echo amto_render_shared_styles();
?>

<div class="tatc-wrap">
    <!-- HERO BANNER -->
    <div class="tatc-hero">
        <div>
            <div class="tatc-hero-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <span>CASR Part 147 &bull; <?php echo s($role_info['code'] . ' • ' . $role_info['label']); ?></span>
            </div>
            <h1>
                <?php if ($is_student): ?>
                    Ujian Kompetensi, Practical Logbook &amp; Hasil Assessment Saya
                <?php else: ?>
                    Manajemen Ujian CBT, Verifikasi Practical Logbook &amp; Nilai Assessment
                <?php endif; ?>
            </h1>
            <p>
                <?php if ($is_student): ?>
                    Kerjakan ujian teori berbasis komputer (<em>Computer-Based Test / CBT</em>), catat aktivitas praktik perawatan pesawat di hangar pada <em>Practical Logbook</em>, dan pantau transkrip kelulusan kompetensi Anda (<em>Passing Grade &ge; 75%</em>).
                <?php else: ?>
                    Kelola bank jadwal ujian teori &amp; evaluasi praktik hangar, verifikasi serta <em>sign-off</em> entri Practical Logbook peserta, dan rekapitulasi nilai kelulusan sesuai standar <strong>CASR Part 147</strong>.
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline" style="<?php echo $active_tab === 'ujian' ? 'background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;' : 'background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);'; ?>">
                <span>1. Daftar Ujian &amp; CBT</span>
            </a>
            <a href="/amto/ujian_logbook.php?tab=logbook" class="tatc-btn-outline" style="<?php echo $active_tab === 'logbook' ? 'background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;' : 'background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);'; ?>">
                <span>2. Practical Logbook Hangar</span>
            </a>
            <a href="/amto/ujian_logbook.php?tab=hasil" class="tatc-btn-outline" style="<?php echo $active_tab === 'hasil' ? 'background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;' : 'background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);'; ?>">
                <span>3. Hasil Assessment &amp; Nilai</span>
            </a>
            <a href="/amto/sertifikat.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                <span>E-Sertifikat AMTO</span>
            </a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="tatc-alert tatc-alert-success">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo s($msg); ?></div>
        </div>
    <?php endif; ?>

    <!-- KPI SUMMARY CARDS -->
    <div class="tatc-stats-grid">
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Modul Ujian Aktif (CASR 147)</div>
                <div class="tatc-stat-value"><?php echo count($exams); ?> Ujian</div>
            </div>
            <div class="tatc-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Practical Logbook Hangar</div>
                <div class="tatc-stat-value" style="color:#15803d;"><?php echo count($logbooks); ?> Entri <span style="font-size:13px; color:#64748b; font-weight:600;">(<?php echo $verified_logs; ?> Verified)</span></div>
            </div>
            <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Hasil Assessment Lulus</div>
                <div class="tatc-stat-value" style="color:#1d4ed8;"><?php echo $passed_exams; ?> / <?php echo count($exam_results); ?> Kompeten</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Ambang Lulus (Passing Grade)</div>
                <div class="tatc-stat-value" style="color:#d97706;">75 / 100</div>
            </div>
            <div class="tatc-stat-icon" style="background:#fef3c7; color:#d97706;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
            </div>
        </div>
    </div>

    <!-- INTERACTIVE CBT EXAM SIMULATION PANEL (WHEN STUDENT CLICKS "KERJAKAN CBT") -->
    <?php if ($selected_cbt_exam): ?>
    <div class="tatc-panel" style="border: 2px solid #dc2626; background: #fffcfc;">
        <div class="tatc-panel-header">
            <div>
                <span class="tatc-badge tatc-badge-rejected" style="margin-bottom:6px;">LEMBAR UJIAN CBT ONLINE AKTIF &bull; <?php echo s($selected_cbt_exam->exam_code); ?></span>
                <h2 class="tatc-panel-title"><?php echo s($selected_cbt_exam->title); ?></h2>
                <p class="tatc-panel-sub">Durasi: <?php echo (int)$selected_cbt_exam->duration_mins; ?> Menit &bull; Passing Grade: <?php echo (int)$selected_cbt_exam->passing_grade; ?>/100 &bull; Examiner: <?php echo s($selected_cbt_exam->assessor_name); ?></p>
            </div>
            <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline">Batal / Tutup Lembar CBT</a>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=hasil">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="submit_cbt">
            <input type="hidden" name="exam_id" value="<?php echo (int)$selected_cbt_exam->id; ?>">

            <div style="display:flex; flex-direction:column; gap:16px;">
                <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                    <div style="font-weight:700; margin-bottom:8px;">1. Berdasarkan regulasi DGCA CASR Part 147, berapakah nilai kelulusan minimum (Passing Grade) ujian modul pengetahuan dasar perawatan pesawat udara?</div>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q1" value="A" required> A. 60% dari total skor</label>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q1" value="B" checked> B. 75% dari total skor (Standar Wajib CASR Part 147)</label>
                    <label style="display:block;"><input type="radio" name="q1" value="C"> C. 50% dari total skor</label>
                </div>

                <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                    <div style="font-weight:700; margin-bottom:8px;">2. Nomor persetujuan resmi (Approved Certificate Number) Aircraft Maintenance Training Organization (AMTO) Telkom University Aviation Training Center dari DKPPU adalah:</div>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q2" value="A" checked required> A. AMTO Approval No. 147D-19</label>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q2" value="B"> B. AMTO Approval No. 121-01</label>
                    <label style="display:block;"><input type="radio" name="q2" value="C"> C. AMTO Approval No. 91-99</label>
                </div>

                <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                    <div style="font-weight:700; margin-bottom:8px;">3. Dalam kurikulum Mandatory Training perawatan aviasi, singkatan dari EWIS adalah:</div>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q3" value="A" required> A. Engine Wing Inspection System</label>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q3" value="B"> B. Electronic Weather Instrument Sensor</label>
                    <label style="display:block;"><input type="radio" name="q3" value="C" checked> C. Electrical Wiring Interconnection System</label>
                </div>

                <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                    <div style="font-weight:700; margin-bottom:8px;">4. Berapakah persentase kehadiran minimum peserta pada sesi teori (Classroom) dan praktik (Hangar) agar memenuhi syarat mengikuti ujian akhir AMTO?</div>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q4" value="A" required> A. Minimal 50% kehadiran</label>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q4" value="B" checked> B. Minimal 80% kehadiran (Sesuai Manual Pelatihan AMTO)</label>
                    <label style="display:block;"><input type="radio" name="q4" value="C"> C. Tidak ada batas minimum kehadiran</label>
                </div>

                <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                    <div style="font-weight:700; margin-bottom:8px;">5. Sebelum dan sesudah praktik di hangar, teknisi wajib melakukan Tool Control &amp; pemeriksaan area kerja untuk mencegah kerusakan pesawat akibat benda asing yang dikenal sebagai:</div>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q5" value="A" checked required> A. FOD (Foreign Object Damage / Debris)</label>
                    <label style="display:block; margin-bottom:4px;"><input type="radio" name="q5" value="B"> B. ILS (Instrument Landing System)</label>
                    <label style="display:block;"><input type="radio" name="q5" value="C"> C. VOR (VHF Omnidirectional Range)</label>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="tatc-btn-red">Kirim Jawaban CBT &amp; Hitung Nilai Otomatis</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- TAB 1: DAFTAR UJIAN & CBT AKTIF -->
    <?php if ($active_tab === 'ujian'): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Ujian Teori (CBT) &amp; Evaluasi Praktik Hangar Aktif</h2>
                <p class="tatc-panel-sub">Jadwal pelaksanaan ujian modul kurikulum CASR Part 147 beserta Examiner / Assessor penguji.</p>
            </div>
            <span class="tatc-badge tatc-badge-open">Standar Lulus: &ge; 75 / 100</span>
        </div>

        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>Kode Ujian</th>
                        <th>Modul &amp; Judul Ujian</th>
                        <th>Batch &amp; Kategori</th>
                        <th>Metode &amp; Durasi</th>
                        <th>Tanggal &amp; Assessor</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($exams)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:32px 16px; color:#64748b;">
                            <strong>Belum ada jadwal atau modul ujian yang dibuat.</strong><br>
                            Silakan login sebagai <strong>Admin / Training Manager / Assessor</strong> untuk menambahkan ujian baru dan menguji sinkronisasi ke akun Peserta.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($exams as $ex): ?>
                    <tr>
                        <td><strong style="color:#b91c1c;"><?php echo s($ex->exam_code); ?></strong></td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;"><?php echo s($ex->title); ?></div>
                            <div style="font-size:12px; color:#64748b;">Passing Grade: <strong><?php echo (int)$ex->passing_grade; ?>/100</strong></div>
                        </td>
                        <td>
                            <code><?php echo s($ex->batch_code); ?></code>
                            <div style="font-size:12px; color:#64748b;"><?php echo s($ex->category_type); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:12.5px;"><?php echo s($ex->exam_type); ?></div>
                            <div style="font-size:12px; color:#64748b;"><?php echo (int)$ex->duration_mins; ?> Menit</div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:12.5px;"><?php echo s($ex->exam_date); ?></div>
                            <div style="font-size:12px; color:#64748b;"><?php echo s($ex->assessor_name); ?></div>
                        </td>
                        <td>
                            <?php if ($can_manage_exam): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <a href="/amto/ujian_logbook.php?tab=hasil" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px;">
                                        Cek Hasil
                                    </a>
                                    <a href="/amto/ujian_logbook.php?tab=ujian&amp;edit_exam=<?php echo (int)$ex->id; ?>" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#1d4ed8 !important; border-color:#bfdbfe; background:#eff6ff;">
                                        Edit
                                    </a>
                                    <form method="post" action="/amto/ujian_logbook.php?tab=ujian" style="display:inline;" onsubmit="return confirm('Hapus ujian ini?');">
                                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                        <input type="hidden" name="action" value="delete_exam">
                                        <input type="hidden" name="exam_id" value="<?php echo (int)$ex->id; ?>">
                                        <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#b91c1c !important; border-color:#fecaca; background:#fef2f2;">
                                            Hapus
                                        </button>
                                    </form>
                                </div>
                            <?php elseif ($is_student): ?>
                                <a href="/amto/ujian_logbook.php?tab=ujian&amp;take_exam=<?php echo (int)$ex->id; ?>" class="tatc-btn-red" style="padding:6px 12px; font-size:12px;">
                                    Kerjakan CBT
                                </a>
                            <?php else: ?>
                                <a href="/amto/ujian_logbook.php?tab=hasil" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px;">
                                    Lihat Nilai
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($can_manage_exam && $editing_exam): ?>
    <div class="tatc-panel" style="border: 2px solid #2563eb; background: #f8fafc;">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Edit Data Ujian: [<?php echo s($editing_exam->exam_code); ?>] <?php echo s($editing_exam->title); ?></h2>
                <p class="tatc-panel-sub">Perbarui kode, judul, batch, tanggal pelaksanaan, durasi, atau nilai Passing Grade ujian ini.</p>
            </div>
            <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline">Batal Edit</a>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=ujian">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="edit_exam">
            <input type="hidden" name="exam_id" value="<?php echo (int)$editing_exam->id; ?>">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Kode Ujian *</label>
                    <input type="text" name="exam_code" class="tatc-input" value="<?php echo s($editing_exam->exam_code); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Judul Modul Ujian *</label>
                    <input type="text" name="title" class="tatc-input" value="<?php echo s($editing_exam->title); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Batch Pelatihan</label>
                    <select name="batch_code" class="tatc-select">
                        <?php foreach ($schedules as $sc): ?>
                            <option value="<?php echo s($sc->batch_code); ?>" <?php echo ($editing_exam->batch_code === $sc->batch_code) ? 'selected' : ''; ?>><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Metode Evaluasi</label>
                    <select name="exam_type" class="tatc-select">
                        <option value="Written CBT (Multiple Choice)" <?php echo ($editing_exam->exam_type === 'Written CBT (Multiple Choice)') ? 'selected' : ''; ?>>Written CBT (Multiple Choice)</option>
                        <option value="Written Essay Exam" <?php echo ($editing_exam->exam_type === 'Written Essay Exam') ? 'selected' : ''; ?>>Written Essay Exam</option>
                        <option value="Practical Assessment (Hangar)" <?php echo ($editing_exam->exam_type === 'Practical Assessment (Hangar)') ? 'selected' : ''; ?>>Practical Assessment (Hangar)</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Tanggal Ujian</label>
                    <input type="date" name="exam_date" class="tatc-input" value="<?php echo s($editing_exam->exam_date); ?>">
                </div>
                <div>
                    <label class="tatc-label">Durasi (Menit) &amp; Passing Grade</label>
                    <div class="d-flex gap-2">
                        <input type="number" name="duration_mins" class="tatc-input" value="<?php echo (int)$editing_exam->duration_mins; ?>" min="15">
                        <input type="number" name="passing_grade" class="tatc-input" value="<?php echo (int)$editing_exam->passing_grade; ?>" min="50" max="100">
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex justify-content-end gap-2">
                <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline">Batal</a>
                <button type="submit" class="tatc-btn-red">Simpan Perubahan Ujian</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($can_manage_exam): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Tambah Jadwal Ujian / Modul Assessment Baru (Assessor &amp; Training Manager)</h2>
                <p class="tatc-panel-sub">Tambahkan jadwal ujian CBT atau Practical Assessment baru untuk Batch pelatihan aktif.</p>
            </div>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=ujian">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add_exam">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Kode Ujian *</label>
                    <input type="text" name="exam_code" class="tatc-input" placeholder="Contoh: EXM-147-M03" required>
                </div>
                <div>
                    <label class="tatc-label">Judul Modul Ujian *</label>
                    <input type="text" name="title" class="tatc-input" placeholder="Contoh: Module 03: Electrical Fundamentals" required>
                </div>
                <div>
                    <label class="tatc-label">Batch Pelatihan</label>
                    <select name="batch_code" class="tatc-select">
                        <?php foreach ($schedules as $sc): ?>
                            <option value="<?php echo s($sc->batch_code); ?>"><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Metode Evaluasi</label>
                    <select name="exam_type" class="tatc-select">
                        <option value="Written CBT (Multiple Choice)">Written CBT (Multiple Choice)</option>
                        <option value="Written Essay Exam">Written Essay Exam</option>
                        <option value="Practical Assessment (Hangar)">Practical Assessment (Hangar)</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Tanggal Ujian</label>
                    <input type="date" name="exam_date" class="tatc-input" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                </div>
                <div>
                    <label class="tatc-label">Durasi (Menit) &amp; Passing Grade</label>
                    <div class="d-flex gap-2">
                        <input type="number" name="duration_mins" class="tatc-input" value="60" min="15">
                        <input type="number" name="passing_grade" class="tatc-input" value="75" min="50" max="100">
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="tatc-btn-red">Simpan Jadwal Ujian Baru</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- TAB 2: PRACTICAL LOGBOOK HANGAR -->
    <?php if ($active_tab === 'logbook'): ?>
    <?php if (!$is_readonly_audit): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Input Entri Practical Logbook Hangar Baru</h2>
                <p class="tatc-panel-sub">Catat aktivitas praktik perawatan pesawat udara, referensi ATA Chapter / AMM, lokasi hangar, dan jam praktik.</p>
            </div>
            <span class="tatc-badge tatc-badge-ongoing">Wajib Sign-Off Instruktur</span>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=logbook">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add_logbook">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Batch Pelatihan *</label>
                    <select name="batch_code" class="tatc-select">
                        <?php foreach ($schedules as $sc): ?>
                            <option value="<?php echo s($sc->batch_code); ?>"><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Tanggal Praktik *</label>
                    <input type="date" name="practice_date" class="tatc-input" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">ATA Chapter &amp; Sistem Pesawat *</label>
                    <input type="text" name="ata_chapter" class="tatc-input" placeholder="Contoh: ATA 24 – Electrical Power / ATA 34 – Navigation" required>
                </div>
                <div>
                    <label class="tatc-label">Judul Pekerjaan / Referensi AMM *</label>
                    <input type="text" name="task_title" class="tatc-input" placeholder="Contoh: Generator & Bus Tie Functional Check per AMM 24-21-00" required>
                </div>
                <div>
                    <label class="tatc-label">Lokasi Hangar / Workshop</label>
                    <input type="text" name="hangar_location" class="tatc-input" value="Hangar B-01 TATC">
                </div>
                <div>
                    <label class="tatc-label">Durasi Jam Praktik &amp; Tools</label>
                    <div class="d-flex gap-2">
                        <input type="text" name="duration_hours" class="tatc-input" value="4.0 Jam" style="max-width:120px;">
                        <input type="text" name="tools_used" class="tatc-input" placeholder="Alat ukur / Special Tools yang digunakan">
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <label class="tatc-label">Deskripsi Pelaksanaan Pekerjaan &amp; Observasi Keselamatan</label>
                <textarea name="description" class="tatc-textarea" rows="2" placeholder="Jelaskan langkah pemeriksaan, hasil pengukuran, dan penerapan prosedur keselamatan FOD/EWIS..."></textarea>
            </div>
            <div class="mt-3">
                <button type="submit" class="tatc-btn-red">Simpan ke Practical Logbook Saya</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Rekaman Practical Logbook Hangar</h2>
                <p class="tatc-panel-sub">Riwayat jam pengalaman praktik perawatan pesawat udara beserta status verifikasi (Sign-Off) Instruktur.</p>
            </div>
            <span class="tatc-badge tatc-badge-approved"><?php echo $verified_logs; ?> Verified / <?php echo $pending_logs; ?> Pending</span>
        </div>
        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>No. Logbook &amp; Tanggal</th>
                        <th>Peserta &amp; Batch</th>
                        <th>ATA Chapter &amp; Task AMM</th>
                        <th>Lokasi &amp; Jam</th>
                        <th>Catatan &amp; Verifikator</th>
                        <th>Status</th>
                        <?php if ($can_verify_logbook): ?><th>Verifikasi Instruktur</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logbooks)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:32px 16px; color:#64748b;">
                            <strong>Belum ada rekaman Practical Logbook Hangar.</strong><br>
                            Peserta dapat menginput catatan praktik hangar pada form di atas untuk kemudian diverifikasi (Sign-Off) oleh Instruktur / Assessor.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($logbooks as $lb): ?>
                    <tr>
                        <td>
                            <strong style="color:#b91c1c;"><?php echo s($lb->log_no); ?></strong>
                            <div style="font-size:12px; color:#64748b;"><?php echo s($lb->practice_date); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:700;"><?php echo s($lb->fullname); ?></div>
                            <code><?php echo s($lb->batch_code); ?></code>
                        </td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;"><?php echo s($lb->ata_chapter); ?></div>
                            <div style="font-size:12.5px; font-weight:600; color:#334155;"><?php echo s($lb->task_title); ?></div>
                            <div style="font-size:11.5px; color:#64748b; margin-top:2px;"><?php echo s($lb->description); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:12.5px;"><?php echo s($lb->hangar_location); ?></div>
                            <div style="font-size:12px; color:#1d4ed8; font-weight:700;"><?php echo s($lb->duration_hours); ?></div>
                        </td>
                        <td>
                            <div style="font-size:12px; color:#334155;"><?php echo s($lb->instructor_notes); ?></div>
                            <?php if (!empty($lb->verified_by)): ?>
                                <div style="font-size:11.5px; color:#15803d; font-weight:700; margin-top:2px;">Sign-Off: <?php echo s($lb->verified_by); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="tatc-badge <?php echo (stripos($lb->status, 'Verified') !== false) ? 'tatc-badge-approved' : ((stripos($lb->status, 'Revisi') !== false) ? 'tatc-badge-rejected' : 'tatc-badge-pending'); ?>">
                                <?php echo s($lb->status); ?>
                            </span>
                        </td>
                        <?php if ($can_verify_logbook): ?>
                        <td>
                            <form method="post" action="/amto/ujian_logbook.php?tab=logbook" class="d-flex flex-column gap-1">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="action" value="verify_logbook">
                                <input type="hidden" name="log_id" value="<?php echo (int)$lb->id; ?>">
                                <input type="text" name="instructor_notes" class="tatc-input" style="padding:4px 8px; font-size:11.5px;" placeholder="Catatan sign-off...">
                                <div class="d-flex gap-1">
                                    <button type="submit" name="decision" value="approve" class="tatc-btn-red" style="padding:4px 8px; font-size:11px; background:#15803d;">Sign-Off</button>
                                    <button type="submit" name="decision" value="revise" class="tatc-btn-outline" style="padding:4px 8px; font-size:11px;">Revisi</button>
                                </div>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB 3: HASIL ASSESSMENT & TRANSKRIP NILAI -->
    <?php if ($active_tab === 'hasil'): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Transkrip Hasil Ujian &amp; Evaluasi Kompetensi (Assessment Results)</h2>
                <p class="tatc-panel-sub">Rekapitulasi nilai ujian teori CBT dan evaluasi praktik hangar berdasarkan ambang batas kelulusan CASR Part 147 (&ge; 75).</p>
            </div>
            <a href="/amto/sertifikat.php" class="tatc-btn-red" style="padding:7px 14px; font-size:12.5px;">Lihat Kelayakan E-Sertifikat</a>
        </div>
        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>Tanggal Evaluasi</th>
                        <th>Kode &amp; Modul Ujian</th>
                        <th>Nama Peserta</th>
                        <th>Skor Nilai</th>
                        <th>Passing Grade</th>
                        <th>Status Kompetensi</th>
                        <th>Examiner / Assessor &amp; Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($exam_results)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:32px 16px; color:#64748b;">
                            <strong>Belum ada hasil ujian / nilai assessment yang tercatat.</strong><br>
                            Nilai akan otomatis muncul setelah Peserta mengerjakan CBT atau setelah Examiner / Assessor menginput nilai evaluasi.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($exam_results as $er): ?>
                    <tr>
                        <td style="font-size:12.5px; color:#475569; white-space:nowrap;"><?php echo date('d M Y, H:i', (int)$er->timecreated); ?></td>
                        <td>
                            <strong style="color:#b91c1c;"><?php echo s($er->exam_code); ?></strong>
                            <div style="font-weight:700; color:#0f172a;"><?php echo s($er->exam_title); ?></div>
                        </td>
                        <td>
                            <strong><?php echo s($er->fullname); ?></strong>
                            <div style="font-size:11.5px; color:#64748b;"><code><?php echo s($er->username); ?></code></div>
                        </td>
                        <td>
                            <span style="font-size:18px; font-weight:800; color:<?php echo ($er->score >= $er->passing_grade) ? '#15803d' : '#b91c1c'; ?>;">
                                <?php echo (int)$er->score; ?>
                            </span>
                            <span style="font-size:12px; color:#64748b;">/ 100</span>
                        </td>
                        <td><strong><?php echo (int)$er->passing_grade; ?></strong></td>
                        <td>
                            <span class="tatc-badge <?php echo ($er->score >= $er->passing_grade) ? 'tatc-badge-approved' : 'tatc-badge-rejected'; ?>">
                                <?php echo s($er->status); ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:12.5px;"><?php echo s($er->assessed_by); ?></div>
                            <div style="font-size:12px; color:#64748b;"><?php echo s($er->notes); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($can_manage_exam): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Input Nilai Assessment Peserta (Khusus Examiner / Assessor &amp; Training Manager)</h2>
                <p class="tatc-panel-sub">Masukkan hasil penilaian ujian tertulis atau evaluasi praktik hangar peserta ke transkrip resmi.</p>
            </div>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=hasil">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="input_grade">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Pilih Modul Ujian *</label>
                    <select name="exam_id" class="tatc-select">
                        <?php foreach ($exams as $ex): ?>
                            <option value="<?php echo (int)$ex->id; ?>"><?php echo s($ex->exam_code . ' – ' . $ex->title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Username Peserta *</label>
                    <input type="text" name="student_username" class="tatc-input" value="peserta" required>
                </div>
                <div>
                    <label class="tatc-label">Skor Nilai (0 - 100) *</label>
                    <input type="number" name="score" class="tatc-input" value="85" min="0" max="100" required>
                </div>
                <div>
                    <label class="tatc-label">Catatan Evaluasi Assessor</label>
                    <input type="text" name="notes" class="tatc-input" placeholder="Contoh: Lulus ujian teori & praktik sesuai standar CASR 147">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="tatc-btn-red">Simpan Nilai Assessment</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();
