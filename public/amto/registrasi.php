<?php
require_once(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');

amto_ensure_tables();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/amto/registrasi.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('TATC Portal – Data & Verifikasi Registrasi Peserta AMTO');
$PAGE->set_heading('Data & Verifikasi Registrasi Peserta');

$role = amto_current_role();
$rbac_role = amto_current_rbac_role();
if ($role === 'student' || $role === 'guest') {
    header('Location: http://localhost:5173/portal/participant/registration');
    exit;
}
$can_verify_reg = in_array($rbac_role, ['admin', 'trainingmanager', 'certofficer'], true);
$is_audit_readonly = in_array($rbac_role, ['qa', 'management', 'adminit'], true);
$notice_msg = '';
$notice_type = 'success';

$preselect_schedule = optional_param('schedule_id', 0, PARAM_INT);
$filter_batch = optional_param('batch', '', PARAM_TEXT);
$check_query = trim(optional_param('q', '', PARAM_TEXT));
$action = optional_param('action', '', PARAM_ALPHA);
$registered_flag = optional_param('registered', 0, PARAM_INT);
$admin_msg = optional_param('admin_msg', '', PARAM_TEXT);

if ($registered_flag === 1 && $check_query !== '') {
    $notice_msg = "Registrasi peserta berhasil dikirim dengan Nomor Pendaftaran <strong>" . s($check_query) . "</strong>. Silakan pantau tabel status di bawah ini (cukup Refresh halaman ini setelah disetujui Admin).";
    $notice_type = 'success';
}
if ($admin_msg !== '') {
    $notice_msg = s($admin_msg);
    $notice_type = 'success';
}

// Helper to handle secure file upload (PDF/JPG/PNG max 5MB per Security Agreement DP-06 & APP-06)
function amto_save_uploaded_doc(string $field, string $regno): string {
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
    $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'];
    $orig_name = basename((string)$_FILES[$field]['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return '';
    }
    if ($_FILES[$field]['size'] <= 0 || $_FILES[$field]['size'] > 5 * 1024 * 1024) {
        return '';
    }
    // [SEC-FIX-03] DP-06 & APP-06: Validate actual file magic bytes / MIME type using finfo
    $tmp_path = $_FILES[$field]['tmp_name'];
    if (!is_uploaded_file($tmp_path)) {
        return '';
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = $finfo ? finfo_file($finfo, $tmp_path) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!in_array($real_mime, $allowed_mimes, true)) {
            return '';
        }
    }
    $upload_dir = __DIR__ . '/uploads';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0750, true);
    }
    $clean_regno = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($regno));
    $clean_field = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($field));
    $safe_name = $clean_regno . '_' . $clean_field . '_' . time() . '.' . $ext;
    $dest = $upload_dir . '/' . $safe_name;
    if (move_uploaded_file($tmp_path, $dest)) {
        return '/amto/uploads/' . $safe_name;
    }
    return '';
}

// 1. Handle Registration Form Submission (Only for Guest / New Applicant or Admin Manual Input)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    // [SEC-FIX-02] APP-01 CSRF Protection via Moodle sesskey validation
    require_sesskey();

    $fullname = trim(optional_param('fullname', '', PARAM_TEXT));
    $username = strtolower(trim(optional_param('username', '', PARAM_USERNAME)));
    $nik = trim(optional_param('nik', '', PARAM_TEXT));
    $email = strtolower(trim(optional_param('email', '', PARAM_EMAIL)));
    $phone = trim(optional_param('phone', '', PARAM_TEXT));
    $organization = trim(optional_param('organization', '', PARAM_TEXT));
    $amel_no = trim(optional_param('amel_no', '', PARAM_TEXT));
    $schedule_id = optional_param('schedule_id', 0, PARAM_INT);
    $raw_password = optional_param('password', '', PARAM_RAW);

    if ($fullname === '' || $username === '' || $nik === '' || $email === '') {
        $notice_msg = 'Mohon lengkapi seluruh kolom wajib (Nama Lengkap, Username, NIK, dan Email).';
        $notice_type = 'danger';
    } else if ($username === 'admin' || $username === 'guest') {
        $notice_msg = 'Username tersebut sudah digunakan oleh sistem. Silakan gunakan username peserta lain.';
        $notice_type = 'danger';
    } else if (strlen($raw_password) < 8) {
        $notice_msg = 'Kata sandi wajib minimal 8 karakter sesuai kebijakan keamanan IAM-03.';
        $notice_type = 'danger';
    } else {
        $existing_reg = $DB->get_record_select(
            'amto_registrations',
            'username = :u AND schedule_id = :s AND status IN (\'pending\', \'approved\')',
            ['u' => $username, 's' => $schedule_id]
        );
        if ($existing_reg) {
            header('Location: /amto/registrasi.php?q=' . urlencode($existing_reg->registration_no) . '&registered=1');
            exit;
        }

        $sched = ($schedule_id > 0) ? $DB->get_record('amto_schedules', ['id' => $schedule_id]) : null;
        $prog_name = $sched ? ($sched->batch_code . ' – ' . $sched->program_name) : 'Verifikasi Kelayakan Peserta (Eligibility Check AMTO)';

        $last_records = $DB->get_records('amto_registrations', null, 'id DESC', 'id', 0, 1);
        $next_num = !empty($last_records) ? (reset($last_records)->id + 1) : 1;
        $reg_no = sprintf('REG-AMTO-2026-%04d', $next_num);

        $doc_ktp = amto_save_uploaded_doc('doc_ktp', $reg_no);
        $doc_ijazah = amto_save_uploaded_doc('doc_ijazah', $reg_no);
        $doc_pendukung = amto_save_uploaded_doc('doc_pendukung', $reg_no);

        $record = new stdClass();
        $record->registration_no = $reg_no;
        $record->userid = 0;
        $record->username = $username;
        $record->fullname = $fullname;
        $record->nik = $nik;
        $record->email = $email;
        $record->phone = $phone;
        $record->organization = $organization ?: 'Mandiri / Umum';
        $record->amel_no = $amel_no;
        $record->schedule_id = $schedule_id;
        $record->program_name = $prog_name;
        $record->password_hash = hash_internal_user_password($raw_password);
        $record->doc_ktp = $doc_ktp;
        $record->doc_ijazah = $doc_ijazah;
        $record->doc_pendukung = $doc_pendukung;
        $record->status = 'pending';
        $record->admin_notes = 'Menunggu verifikasi berkas prasyarat oleh Admin / Training Manager.';
        $record->created_by = (isloggedin() && !isguestuser()) ? (int)$USER->id : 0;
        $record->verified_by = 0;
        $record->timecreated = time();
        $record->timemodified = time();

        $new_reg_id = $DB->insert_record('amto_registrations', $record);
        if (function_exists('amto_sync_cross_db')) {
            amto_sync_cross_db(true);
        }
        if (function_exists('amto_log_audit_event')) {
            amto_log_audit_event('REGISTRATION_CREATE', 'amto_registrations', (int)$new_reg_id, "reg_no={$reg_no}, status=pending", "New participant registration submitted for username={$username}, program={$prog_name}");
        }

        if ($role === 'admin') {
            header('Location: /amto/registrasi.php?admin_msg=' . urlencode("Data pendaftar baru {$reg_no} ({$fullname}) berhasil ditambahkan."));
            exit;
        }
        header('Location: /amto/registrasi.php?q=' . urlencode($reg_no) . '&registered=1');
        exit;
    }
}

// 2. Handle Admin Approval / Rejection / Soft-Delete (Void) with RBAC & SoD enforcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_verify_reg && in_array($action, ['approve', 'reject', 'delete'], true)) {
    // [SEC-FIX-02] APP-01 CSRF Protection on critical state-changing actions
    require_sesskey();

    $regid = required_param('regid', PARAM_INT);
    $notes = trim(optional_param('admin_notes', '', PARAM_TEXT));
    $reg = $DB->get_record('amto_registrations', ['id' => $regid]);

    if ($reg) {
        if ($action === 'delete') {
            // [SEC-FIX-04] SEC-05 & Bab 3/7 Form Kesepakatan RBAC: No Hard Delete on transaction records; use Soft Delete / Void
            $reg->status = 'void';
            $void_reason = $notes !== '' ? $notes : 'Dibatalkan (Void) oleh pejabat berwenang dengan jejak audit tetap tersimpan.';
            $reg->admin_notes = '[VOID oleh ' . fullname($USER) . ' pada ' . date('Y-m-d H:i') . '] ' . $void_reason;
            $reg->verified_by = $USER->id;
            $reg->timemodified = time();
            $DB->update_record('amto_registrations', $reg);
            if (function_exists('amto_log_audit_event')) {
                amto_log_audit_event('REGISTRATION_VOID', 'amto_registrations', $regid, 'status=void', $reg->admin_notes);
            }
            $msg = "Data pendaftaran {$reg->registration_no} ({$reg->fullname}) telah ditandai VOID (Soft-Delete SEC-05) tanpa menghapus jejak audit.";
            header('Location: /amto/registrasi.php?admin_msg=' . urlencode($msg));
            exit;
        } else if ($action === 'approve') {
            // [SEC-FIX-04] SEC-03 Segregation of Duties (SoD): Prevent self-approval if staff created the record manually
            if (!amto_check_sod_approval((int)($reg->created_by ?? 0), (int)$USER->id)) {
                if (function_exists('amto_log_audit_event')) {
                    amto_log_audit_event('SOD_VIOLATION_BLOCKED', 'amto_registrations', $regid, 'Self-Approval Blocked', 'User attempted to approve registration created by themselves');
                }
                $notice_msg = 'Pelanggaran Segregation of Duties (SEC-03): Akun yang menginput pendaftaran manual tidak boleh menyetujui (Self-Approve) transaksi yang dibuatnya sendiri. Mohon gunakan akun verifikator independen.';
                $notice_type = 'danger';
            } else {
            $existing = $DB->get_record('user', [
                'username' => $reg->username,
                'mnethostid' => $CFG->mnet_localhost_id,
            ]);

            $parts = preg_split('/\s+/', trim($reg->fullname), 2);
            $firstname = $parts[0] ?? $reg->fullname;
            $lastname = (!empty($parts[1])) ? $parts[1] : 'Peserta AMTO';

            if ($existing) {
                $existing->password = $reg->password_hash;
                $existing->confirmed = 1;
                $existing->deleted = 0;
                $existing->suspended = 0;
                $existing->firstname = $firstname;
                $existing->lastname = $lastname;
                $existing->email = $reg->email;
                $existing->timemodified = time();
                $DB->update_record('user', $existing);
                $target_userid = $existing->id;
            } else {
                $newuser = new stdClass();
                $newuser->auth = 'manual';
                $newuser->confirmed = 1;
                $newuser->policyagreed = 1;
                $newuser->deleted = 0;
                $newuser->suspended = 0;
                $newuser->mnethostid = $CFG->mnet_localhost_id;
                $newuser->username = $reg->username;
                $newuser->password = $reg->password_hash;
                $newuser->firstname = $firstname;
                $newuser->lastname = $lastname;
                $newuser->email = $reg->email;
                $newuser->phone1 = $reg->phone;
                $newuser->institution = $reg->organization;
                $newuser->idnumber = $reg->registration_no;
                $newuser->city = 'Bandung';
                $newuser->country = 'ID';
                $newuser->lang = 'en';
                $newuser->timecreated = time();
                $newuser->timemodified = time();

                $target_userid = $DB->insert_record('user', $newuser);
            }

            $studentrole = $DB->get_record('role', ['shortname' => 'student']);
            if ($studentrole && $target_userid) {
                role_assign($studentrole->id, $target_userid, $context->id);
            }

            $sched = $DB->get_record('amto_schedules', ['id' => $reg->schedule_id]);
            if ($sched && $target_userid) {
                $cohort = $DB->get_record('cohort', ['idnumber' => $sched->batch_code]);
                if (!$cohort) {
                    $c = new stdClass();
                    $c->contextid = $context->id;
                    $c->name = $sched->batch_code . ' – ' . $sched->program_name;
                    $c->idnumber = $sched->batch_code;
                    $c->description = 'Angkatan Pelatihan AMTO: ' . $sched->program_name;
                    $c->descriptionformat = FORMAT_HTML;
                    $c->visible = 1;
                    $cohortid = cohort_add_cohort($c);
                } else {
                    $cohortid = $cohort->id;
                }
                if (!empty($cohortid)) {
                    cohort_add_member($cohortid, $target_userid);
                }
            }

            $reg->userid = $target_userid;
            $reg->status = 'approved';
            $reg->admin_notes = $notes !== '' ? $notes : 'Berkas lengkap & terverifikasi. Akun LMS telah diaktifkan dan dimasukkan ke Batch Pelatihan.';
            $reg->verified_by = $USER->id;
            $reg->timemodified = time();
            $DB->update_record('amto_registrations', $reg);
            if (function_exists('amto_sync_cross_db')) {
                amto_sync_cross_db(true);
            }
            if (function_exists('amto_log_audit_event')) {
                amto_log_audit_event('REGISTRATION_APPROVE', 'amto_registrations', $regid, 'status: pending -> approved', "Approved registration {$reg->registration_no} ({$reg->username}), linked Moodle userid={$target_userid}");
            }

            $msg = "Pendaftaran {$reg->registration_no} ({$reg->fullname}) telah DISETUJUI. Akun LMS (username: {$reg->username}) sudah aktif dan siap digunakan login!";
            header('Location: /amto/registrasi.php?admin_msg=' . urlencode($msg));
            exit;
            }
        } else {
            $old_status = $reg->status;
            $reg->status = 'rejected';
            $reg->admin_notes = $notes !== '' ? $notes : 'Berkas prasyarat belum sesuai/kurang jelas. Mohon unggah ulang dokumen yang valid.';
            $reg->verified_by = $USER->id;
            $reg->timemodified = time();
            $DB->update_record('amto_registrations', $reg);
            if (function_exists('amto_log_audit_event')) {
                amto_log_audit_event('REGISTRATION_REJECT', 'amto_registrations', $regid, "status: {$old_status} -> rejected", $reg->admin_notes);
            }

            $msg = "Pendaftaran {$reg->registration_no} ({$reg->fullname}) telah ditandai Perlu Revisi / Ditolak.";
            header('Location: /amto/registrasi.php?admin_msg=' . urlencode($msg));
            exit;
        }
    }
}

$schedules = $DB->get_records('amto_schedules', null, 'start_date ASC');
$all_regs = $DB->get_records('amto_registrations', null, 'timecreated DESC');

$total_count = count($all_regs);
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
foreach ($all_regs as $r) {
    if ($r->status === 'pending') {
        $pending_count++;
    } else if ($r->status === 'approved') {
        $approved_count++;
    } else if ($r->status === 'rejected') {
        $rejected_count++;
    }
}

// Filter visible rows based on role
$visible_regs = [];
foreach ($all_regs as $r) {
    if ($role === 'admin') {
        if ($filter_batch !== '' && stripos($r->program_name, $filter_batch) === false) {
            continue;
        }
        $visible_regs[] = $r;
    } else if ($role === 'student' && isloggedin() && !isguestuser()) {
        if ((int)$r->userid === (int)$USER->id || strtolower($r->username) === strtolower($USER->username)) {
            $visible_regs[] = $r;
        }
    } else {
        if ($check_query !== '' && (
            strcasecmp($r->registration_no, $check_query) === 0 ||
            strcasecmp($r->email, $check_query) === 0 ||
            strcasecmp($r->username, $check_query) === 0
        )) {
            $visible_regs[] = $r;
        }
    }
}

echo $OUTPUT->header();
echo amto_render_shared_styles();
?>

<div class="tatc-wrap">
    <!-- HERO BANNER -->
    <div class="tatc-hero">
        <div>
            <div class="tatc-hero-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                <span>CASR Part 147 &bull; Participant Admission &amp; Verification</span>
            </div>
            <h1>
                <?php
                if ($role === 'admin') {
                    echo 'Manajemen Registrasi & Verifikasi Peserta';
                } else if ($role === 'student' && isloggedin() && !isguestuser()) {
                    echo 'Data & Status Berkas Registrasi Saya';
                } else {
                    echo 'Registrasi Peserta Pelatihan AMTO';
                }
                ?>
            </h1>
            <p>
                <?php if ($role === 'admin'): ?>
                    Verifikasi kelengkapan dokumen prasyarat calon peserta (KTP, Ijazah, AMEL/Lisensi). Saat Anda mengklik <strong>Approve &amp; Aktifkan LMS</strong>, akun LMS peserta otomatis dibuat dan dimasukkan ke Cohort Batch jadwal pelatihan.
                <?php elseif ($role === 'student' && isloggedin() && !isguestuser()): ?>
                    Berikut adalah rincian data pendaftaran, program batch pelatihan yang Anda ikuti, serta berkas prasyarat yang telah terverifikasi di sistem TATC – AMTO.
                <?php else: ?>
                    Pendaftaran resmi calon peserta pelatihan Aircraft Maintenance Training Organization (AMTO) – TATC. Pilih jadwal batch, lengkapi biodata, dan unggah dokumen prasyarat.
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/amto/jadwal.php" class="tatc-btn-outline" style="background: rgba(255,255,255,0.14); color:#fff !important; border-color: rgba(255,255,255,0.3);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>Lihat Jadwal &amp; Batch Pelatihan</span>
            </a>
            <?php if ($role === 'admin' || ($role === 'student' && empty($visible_regs))): ?>
            <button type="button" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;" onclick="var f=document.getElementById('tatcRegFormPanel'); if(f){ f.style.display = (f.style.display==='none' ? 'block' : 'none'); }">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span><?php echo ($role === 'admin') ? 'Input Pendaftar Baru' : 'Ajukan Verifikasi Kelayakan (Eligibility)'; ?></span>
            </button>
            <?php elseif ($role === 'guest'): ?>
            <a href="/login/index.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                <span>Halaman Login LMS</span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($notice_msg !== ''): ?>
        <div class="alert alert-<?php echo $notice_type; ?> mb-4" style="border-radius:12px; font-size:14px;">
            <?php echo $notice_msg; ?>
        </div>
    <?php endif; ?>

    <?php if ($role === 'admin'): ?>
    <!-- KPI SUMMARY CARDS FOR ADMIN -->
    <div class="tatc-stats-grid">
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Total Pendaftar Masuk</div>
                <div class="tatc-stat-value"><?php echo $total_count; ?></div>
            </div>
            <div class="tatc-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Menunggu Verifikasi (Pending)</div>
                <div class="tatc-stat-value" style="color:#d97706;"><?php echo $pending_count; ?></div>
            </div>
            <div class="tatc-stat-icon" style="background:#fef3c7; color:#d97706;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Disetujui &amp; Akun LMS Aktif</div>
                <div class="tatc-stat-value" style="color:#15803d;"><?php echo $approved_count; ?></div>
            </div>
            <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Perlu Revisi / Ditolak</div>
                <div class="tatc-stat-value" style="color:#b91c1c;"><?php echo $rejected_count; ?></div>
            </div>
            <div class="tatc-stat-icon" style="background:#fee2e2; color:#b91c1c;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- TABEL VERIFIKASI ADMIN ATAU STATUS REGISTRASI PESERTA -->
    <?php if ($role === 'admin' || ($role === 'student' && isloggedin() && !isguestuser()) || $check_query !== '' || !empty($visible_regs)): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">
                    <?php
                    if ($role === 'admin') {
                        echo 'Daftar Antrean Verifikasi & Peserta Terdaftar';
                    } else if ($role === 'student' && isloggedin() && !isguestuser()) {
                        echo 'Kartu Bukti Registrasi & Berkas Prasyarat Saya';
                    } else {
                        echo 'Status & Hasil Verifikasi Pendaftaran Anda';
                    }
                    ?>
                </h2>
                <p class="tatc-panel-sub">
                    <?php
                    if ($role === 'admin') {
                        echo 'Klik tombol "Approve & Aktifkan LMS" setelah memeriksa dokumen untuk mengaktifkan akun Moodle & memasukkan peserta ke Cohort Batch.';
                    } else if ($role === 'student' && isloggedin() && !isguestuser()) {
                        echo 'Akun Anda telah terverifikasi dan aktif sebagai Peserta Pelatihan TATC – AMTO.';
                    } else {
                        echo 'Refresh halaman ini untuk memperbarui status verifikasi berkas Anda secara real-time.';
                    }
                    ?>
                </p>
            </div>
            <?php if ($role === 'admin'): ?>
            <div class="d-flex gap-2">
                <a href="/amto/audit_log.php" class="tatc-btn-outline" style="border-color:#fca5a5; color:#b91c1c !important;">🛡️ Audit Trail &amp; RBAC</a>
                <a href="/admin/user.php" class="tatc-btn-outline">Daftar Akun Moodle</a>
                <a href="/cohort/index.php" class="tatc-btn-outline">Lihat Cohort / Angkatan</a>
            </div>
            <?php elseif ($role === 'guest'): ?>
            <form method="get" action="/amto/registrasi.php" class="d-flex gap-2">
                <input type="text" name="q" class="tatc-input" style="min-width:240px; padding:7px 12px;" placeholder="Cari No. Reg / Username / Email..." value="<?php echo s($check_query); ?>">
                <button type="submit" class="tatc-btn-outline">Refresh / Cek Status</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($visible_regs)): ?>
            <div class="text-center py-4">
                <p class="text-muted mb-1" style="font-size:15px;">Belum ada data registrasi peserta yang ditampilkan.</p>
            </div>
        <?php else: ?>
            <div class="tatc-table-wrap">
                <table class="tatc-table">
                    <thead>
                        <tr>
                            <th>No. Registrasi</th>
                            <th>Data Peserta</th>
                            <th>Instansi &amp; Lisensi</th>
                            <th>Program &amp; Batch</th>
                            <th>Berkas Prasyarat</th>
                            <th>Status Verifikasi</th>
                            <?php if ($role === 'admin'): ?>
                                <th>Aksi Verifikator (Admin)</th>
                            <?php else: ?>
                                <th>Aksi Peserta</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visible_regs as $r): ?>
                            <tr>
                                <td>
                                    <strong style="color:#b91c1c; font-size:13px;"><?php echo s($r->registration_no); ?></strong>
                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                        <?php echo date('d M Y, H:i', (int)$r->timecreated); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    // [SEC-FIX-06] DP-01, SCP-01 & SCP-07: Mask sensitive PII (NIK, Phone, Email) for unauthenticated Guest & Management aggregate role
                                    $should_mask_pii = ($role === 'guest' || $rbac_role === 'management');
                                    $disp_nik   = $should_mask_pii ? amto_mask_nik((string)$r->nik) : (string)$r->nik;
                                    $disp_phone = $should_mask_pii ? amto_mask_phone((string)$r->phone) : (string)$r->phone;
                                    $disp_email = ($role === 'guest') ? amto_mask_email((string)$r->email) : (string)$r->email;
                                    ?>
                                    <div style="font-weight:700; color:#0f172a;"><?php echo s($r->fullname); ?></div>
                                    <div style="font-size:12px; color:#64748b;">
                                        Username: <code><?php echo s($r->username); ?></code> &bull; NIK: <?php echo s($disp_nik); ?>
                                    </div>
                                    <div style="font-size:12px; color:#64748b;">
                                        <?php echo s($disp_email); ?> &bull; <?php echo s($disp_phone); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?php echo s($r->organization); ?></div>
                                    <?php if (!empty($r->amel_no)): ?>
                                        <div style="font-size:11.5px; color:#475569;">AMEL: <?php echo s($r->amel_no); ?></div>
                                    <?php else: ?>
                                        <div style="font-size:11.5px; color:#94a3b8;">Non-AMEL / Basic Baru</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:#0f172a; max-width:250px;"><?php echo s($r->program_name); ?></div>
                                </td>
                                <td>
                                    <?php if ($role !== 'guest' && isloggedin() && !isguestuser()): ?>
                                        <?php if (!empty($r->doc_ktp)): ?>
                                            <a href="/amto/download.php?type=reg&amp;id=<?php echo (int)$r->id; ?>&amp;doc=ktp" target="_blank" class="tatc-doc-pill">KTP</a>
                                        <?php endif; ?>
                                        <?php if (!empty($r->doc_ijazah)): ?>
                                            <a href="/amto/download.php?type=reg&amp;id=<?php echo (int)$r->id; ?>&amp;doc=ijazah" target="_blank" class="tatc-doc-pill">Ijazah</a>
                                        <?php endif; ?>
                                        <?php if (!empty($r->doc_pendukung)): ?>
                                            <a href="/amto/download.php?type=reg&amp;id=<?php echo (int)$r->id; ?>&amp;doc=pendukung" target="_blank" class="tatc-doc-pill">Surat/Foto</a>
                                        <?php endif; ?>
                                        <?php if (empty($r->doc_ktp) && empty($r->doc_ijazah) && empty($r->doc_pendukung)): ?>
                                            <span style="font-size:12px; color:#94a3b8;">Belum unggah file</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="tatc-doc-pill" title="DP-07: Login untuk mengakses dokumen terproteksi">🔒 Terlindungi (DP-07)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r->status === 'approved'): ?>
                                        <span class="tatc-badge tatc-badge-approved">Disetujui &amp; Aktif</span>
                                    <?php elseif ($r->status === 'rejected'): ?>
                                        <span class="tatc-badge tatc-badge-rejected">Perlu Revisi / Ditolak</span>
                                    <?php elseif ($r->status === 'void'): ?>
                                        <span class="tatc-badge tatc-badge-rejected" style="background:#e2e8f0; color:#475569;">VOID (Soft-Deleted)</span>
                                    <?php else: ?>
                                        <span class="tatc-badge tatc-badge-pending">Menunggu Verifikasi</span>
                                    <?php endif; ?>
                                    <?php if (!empty($r->admin_notes)): ?>
                                        <div style="font-size:11.5px; color:#64748b; margin-top:4px; max-width:220px;">
                                            <?php echo s($r->admin_notes); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($role === 'admin'): ?>
                                <td>
                                    <?php if ($is_audit_readonly): ?>
                                        <span class="tatc-doc-pill" title="SCP-06 / SCP-08: Role Anda bersifat Read-Only Audit / Teknis">🔍 Read-Only (<?php echo s(strtoupper($rbac_role)); ?>)</span>
                                    <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if ($r->status !== 'approved' && $r->status !== 'void'): ?>
                                        <form method="post" action="/amto/registrasi.php" style="display:inline;">
                                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="regid" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="tatc-btn-red" style="padding:6px 12px; font-size:12px;">
                                                Approve &amp; Aktifkan LMS
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($r->status !== 'rejected' && $r->status !== 'void'): ?>
                                        <form method="post" action="/amto/registrasi.php" style="display:inline;">
                                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="regid" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#b91c1c !important; border-color:#fecaca;">
                                                Tolak / Revisi
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($r->status !== 'void'): ?>
                                        <form method="post" action="/amto/registrasi.php" style="display:inline;" onsubmit="return confirm('Tandai pendaftaran ini sebagai VOID (Soft-Delete sesuai SEC-05)?');">
                                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="regid" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px;">
                                                Void
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <?php else: ?>
                                <td>
                                    <?php if (isloggedin() && !isguestuser()): ?>
                                        <a href="/amto/jadwal.php" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;">
                                            Lihat Jadwal Batch Saya
                                        </a>
                                    <?php elseif ($r->status === 'approved'): ?>
                                        <a href="/login/index.php" class="tatc-btn-red" style="padding:7px 14px; font-size:12px;">
                                            Login ke LMS Sekarang
                                        </a>
                                    <?php else: ?>
                                        <a href="/amto/registrasi.php?q=<?php echo urlencode($r->registration_no); ?>" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;">
                                            Cek Ulang Status
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- FORM PENDAFTARAN & VERIFIKASI KELAYAKAN PESERTA (ELIGIBILITY CHECK AMTO) -->
    <?php if ($role === 'guest' || $role === 'admin' || ($role === 'student' && empty($visible_regs))): ?>
    <div id="tatcRegFormPanel" class="tatc-panel" style="<?php echo ($role === 'admin' || $check_query !== '') ? 'display:none;' : 'display:block;'; ?>">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Formulir Pendaftaran &amp; Verifikasi Kelayakan Peserta (Eligibility Check AMTO)</h2>
                <p class="tatc-panel-sub">Lengkapi identitas sesuai KTP dan unggah dokumen prasyarat (KTP, Ijazah, AMEL/Surat Tugas — PDF/JPG/PNG, Maks. 5 MB) untuk diperiksa kelayakannya oleh Admin / Training Manager.</p>
            </div>
            <span class="tatc-badge tatc-badge-open">Verifikasi Eligibility Peserta</span>
        </div>

        <form method="post" action="/amto/registrasi.php" enctype="multipart/form-data">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="register">

            <div class="tatc-form-grid mb-3">
                <div>
                    <label class="tatc-label">Nama Lengkap (Sesuai KTP &amp; Sertifikat) *</label>
                    <input type="text" name="fullname" class="tatc-input" placeholder="Contoh: Andi Pratama Wijaya" value="<?php echo ($role === 'student' && isloggedin()) ? s(fullname($USER)) : ''; ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Username Akun LMS *</label>
                    <input type="text" name="username" class="tatc-input" placeholder="Contoh: samuelm (huruf kecil tanpa spasi)" value="<?php echo ($role === 'student' && isloggedin()) ? s($USER->username) : ''; ?>" required>
                </div>
                <div>
                    <label class="tatc-label">NIK (Nomor Induk Kependudukan) *</label>
                    <input type="text" name="nik" class="tatc-input" placeholder="16 Digit NIK KTP" required>
                </div>
                <div>
                    <label class="tatc-label">Alamat Email Resmi *</label>
                    <input type="email" name="email" class="tatc-input" placeholder="nama@perusahaan.com" value="<?php echo ($role === 'student' && isloggedin()) ? s($USER->email) : ''; ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Nomor Telepon / WhatsApp *</label>
                    <input type="text" name="phone" class="tatc-input" placeholder="0812xxxxxxxx" required>
                </div>
                <div>
                    <label class="tatc-label">Asal Instansi / Maskapai / Mandiri *</label>
                    <input type="text" name="organization" class="tatc-input" placeholder="Contoh: Telkom University / GMF AeroAsia" required>
                </div>
                <div>
                    <label class="tatc-label">Nomor Lisensi AMEL / Basic Certificate (Opsional)</label>
                    <input type="text" name="amel_no" class="tatc-input" placeholder="Kosongkan jika belum memiliki AMEL">
                </div>
                <div>
                    <label class="tatc-label">Password Akun LMS (Min. 8 Karakter - Standar IAM-03) *</label>
                    <input type="password" name="password" class="tatc-input" placeholder="Min. 8 karakter kombinasi huruf & angka" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="tatc-label">Tujuan Verifikasi Kelayakan / Jadwal Pelatihan (Opsional jika jadwal belum dibuat)</label>
                <select name="schedule_id" class="tatc-select">
                    <option value="0">Verifikasi Kelayakan Peserta Umum (Eligibility Check AMTO CASR Part 147)</option>
                    <?php foreach ($schedules as $s): ?>
                        <option value="<?php echo $s->id; ?>" <?php echo ($preselect_schedule == $s->id) ? 'selected' : ''; ?>>
                            [<?php echo s($s->batch_code); ?>] <?php echo s($s->program_name); ?> &mdash; Mulai: <?php echo s($s->start_date); ?> (<?php echo s($s->room_location); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tatc-form-grid mb-4">
                <div>
                    <label class="tatc-label">Unggah Scan KTP / ID Card (PDF/JPG/PNG, Maks 5MB)</label>
                    <input type="file" name="doc_ktp" class="tatc-input" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div>
                    <label class="tatc-label">Unggah Ijazah Terakhir / Basic Certificate (PDF/JPG/PNG)</label>
                    <input type="file" name="doc_ijazah" class="tatc-input" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div>
                    <label class="tatc-label">Unggah Surat Tugas / Pas Foto (PDF/JPG/PNG)</label>
                    <input type="file" name="doc_pendukung" class="tatc-input" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <button type="submit" class="tatc-btn-red">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <span>Kirim Berkas Verifikasi Kelayakan Peserta</span>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($role === 'guest' && $check_query === ''): ?>
    <!-- QUICK STATUS SEARCH BOX FOR GUESTS -->
    <div class="tatc-panel">
        <div class="tatc-panel-header" style="border-bottom:none; margin-bottom:0; padding-bottom:0;">
            <div>
                <h2 class="tatc-panel-title">Sudah Mendaftar Sebelumnya? Cek Status Verifikasi</h2>
                <p class="tatc-panel-sub">Masukkan Nomor Registrasi (misal: <code>REG-AMTO-2026-0001</code>) atau Username (misal: <code>samuelm</code>) untuk mengecek status kelulusan verifikasi berkas Anda.</p>
            </div>
            <form method="get" action="/amto/registrasi.php" class="d-flex gap-2">
                <input type="text" name="q" class="tatc-input" style="min-width:240px; padding:8px 12px;" placeholder="Ketik No. Reg / Username...">
                <button type="submit" class="tatc-btn-red">Cek Status</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();
