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

// Helper to handle secure file upload (PDF/JPG/PNG max 5MB per Security Agreement)
function amto_save_uploaded_doc(string $field, string $regno): string {
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
    $orig_name = $_FILES[$field]['name'];
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return '';
    }
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
        return '';
    }
    $upload_dir = __DIR__ . '/uploads';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }
    $safe_name = strtolower($regno . '_' . $field . '_' . time() . '.' . $ext);
    $dest = $upload_dir . '/' . $safe_name;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
        return '/amto/uploads/' . $safe_name;
    }
    return '';
}

// 1. Handle Registration Form Submission (Only for Guest / New Applicant or Admin Manual Input)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    $fullname = trim(optional_param('fullname', '', PARAM_TEXT));
    $username = strtolower(trim(optional_param('username', '', PARAM_USERNAME)));
    $nik = trim(optional_param('nik', '', PARAM_TEXT));
    $email = strtolower(trim(optional_param('email', '', PARAM_EMAIL)));
    $phone = trim(optional_param('phone', '', PARAM_TEXT));
    $organization = trim(optional_param('organization', '', PARAM_TEXT));
    $amel_no = trim(optional_param('amel_no', '', PARAM_TEXT));
    $schedule_id = optional_param('schedule_id', 0, PARAM_INT);
    $raw_password = optional_param('password', '', PARAM_RAW);

    if ($fullname === '' || $username === '' || $nik === '' || $email === '' || $schedule_id <= 0) {
        $notice_msg = 'Mohon lengkapi seluruh kolom wajib (Nama Lengkap, Username, NIK, Email, dan Program Pelatihan).';
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

        $sched = $DB->get_record('amto_schedules', ['id' => $schedule_id]);
        $prog_name = $sched ? ($sched->batch_code . ' – ' . $sched->program_name) : 'Program Pelatihan AMTO';

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
        $record->verified_by = 0;
        $record->timecreated = time();
        $record->timemodified = time();

        $DB->insert_record('amto_registrations', $record);

        if ($role === 'admin') {
            header('Location: /amto/registrasi.php?admin_msg=' . urlencode("Data pendaftar baru {$reg_no} ({$fullname}) berhasil ditambahkan."));
            exit;
        }
        header('Location: /amto/registrasi.php?q=' . urlencode($reg_no) . '&registered=1');
        exit;
    }
}

// 2. Handle Admin Approval / Rejection / Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'admin' && in_array($action, ['approve', 'reject', 'delete'], true)) {
    $regid = required_param('regid', PARAM_INT);
    $notes = trim(optional_param('admin_notes', '', PARAM_TEXT));
    $reg = $DB->get_record('amto_registrations', ['id' => $regid]);

    if ($reg) {
        if ($action === 'delete') {
            $DB->delete_records('amto_registrations', ['id' => $regid]);
            $msg = "Data pendaftaran {$reg->registration_no} ({$reg->fullname}) berhasil dihapus.";
            header('Location: /amto/registrasi.php?admin_msg=' . urlencode($msg));
            exit;
        } else if ($action === 'approve') {
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

            $msg = "Pendaftaran {$reg->registration_no} ({$reg->fullname}) telah DISETUJUI. Akun LMS (username: {$reg->username}) sudah aktif dan siap digunakan login!";
            header('Location: /amto/registrasi.php?admin_msg=' . urlencode($msg));
            exit;
        } else {
            $reg->status = 'rejected';
            $reg->admin_notes = $notes !== '' ? $notes : 'Berkas prasyarat belum sesuai/kurang jelas. Mohon unggah ulang dokumen yang valid.';
            $reg->verified_by = $USER->id;
            $reg->timemodified = time();
            $DB->update_record('amto_registrations', $reg);

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
            <?php if ($role === 'admin'): ?>
            <button type="button" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;" onclick="var f=document.getElementById('tatcRegFormPanel'); f.style.display = (f.style.display==='none' ? 'block' : 'none');">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Input Pendaftar Baru</span>
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
                                    <div style="font-weight:700; color:#0f172a;"><?php echo s($r->fullname); ?></div>
                                    <div style="font-size:12px; color:#64748b;">
                                        Username: <code><?php echo s($r->username); ?></code> &bull; NIK: <?php echo s($r->nik); ?>
                                    </div>
                                    <div style="font-size:12px; color:#64748b;">
                                        <?php echo s($r->email); ?> &bull; <?php echo s($r->phone); ?>
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
                                    <?php if (!empty($r->doc_ktp)): ?>
                                        <a href="<?php echo s($r->doc_ktp); ?>" target="_blank" class="tatc-doc-pill">KTP</a>
                                    <?php endif; ?>
                                    <?php if (!empty($r->doc_ijazah)): ?>
                                        <a href="<?php echo s($r->doc_ijazah); ?>" target="_blank" class="tatc-doc-pill">Ijazah</a>
                                    <?php endif; ?>
                                    <?php if (!empty($r->doc_pendukung)): ?>
                                        <a href="<?php echo s($r->doc_pendukung); ?>" target="_blank" class="tatc-doc-pill">Surat/Foto</a>
                                    <?php endif; ?>
                                    <?php if (empty($r->doc_ktp) && empty($r->doc_ijazah) && empty($r->doc_pendukung)): ?>
                                        <span style="font-size:12px; color:#94a3b8;">Belum unggah file</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r->status === 'approved'): ?>
                                        <span class="tatc-badge tatc-badge-approved">Disetujui &amp; Aktif</span>
                                    <?php elseif ($r->status === 'rejected'): ?>
                                        <span class="tatc-badge tatc-badge-rejected">Perlu Revisi / Ditolak</span>
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
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if ($r->status !== 'approved'): ?>
                                        <form method="post" action="/amto/registrasi.php" style="display:inline;">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="regid" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="tatc-btn-red" style="padding:6px 12px; font-size:12px;">
                                                Approve &amp; Aktifkan LMS
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($r->status !== 'rejected'): ?>
                                        <form method="post" action="/amto/registrasi.php" style="display:inline;">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="regid" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#b91c1c !important; border-color:#fecaca;">
                                                Tolak / Revisi
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="post" action="/amto/registrasi.php" style="display:inline;" onsubmit="return confirm('Hapus data pendaftaran ini?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="regid" value="<?php echo $r->id; ?>">
                                            <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px;">
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
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

    <!-- FORM PENDAFTARAN PESERTA BARU: Hanya tampil untuk Guest (belum login) atau ketika Admin klik Input Pendaftar Baru -->
    <?php if ($role === 'guest' || $role === 'admin'): ?>
    <div id="tatcRegFormPanel" class="tatc-panel" style="<?php echo ($role === 'admin' || $check_query !== '') ? 'display:none;' : 'display:block;'; ?>">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Formulir Pendaftaran Peserta Pelatihan AMTO Baru</h2>
                <p class="tatc-panel-sub">Lengkapi identitas sesuai KTP, pilih jadwal batch pelatihan, dan unggah dokumen prasyarat (Format: PDF/JPG/PNG, Maks. 5 MB).</p>
            </div>
            <span class="tatc-badge tatc-badge-open">Standar IAM-03 &amp; Validasi Berkas</span>
        </div>

        <form method="post" action="/amto/registrasi.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="register">

            <div class="tatc-form-grid mb-3">
                <div>
                    <label class="tatc-label">Nama Lengkap (Sesuai KTP &amp; Sertifikat) *</label>
                    <input type="text" name="fullname" class="tatc-input" placeholder="Contoh: Andi Pratama Wijaya" required>
                </div>
                <div>
                    <label class="tatc-label">Username Akun LMS (Untuk Login Nanti) *</label>
                    <input type="text" name="username" class="tatc-input" placeholder="Contoh: samuelm (huruf kecil tanpa spasi)" required>
                </div>
                <div>
                    <label class="tatc-label">NIK (Nomor Induk Kependudukan) *</label>
                    <input type="text" name="nik" class="tatc-input" placeholder="16 Digit NIK KTP" required>
                </div>
                <div>
                    <label class="tatc-label">Alamat Email Resmi *</label>
                    <input type="email" name="email" class="tatc-input" placeholder="nama@perusahaan.com" required>
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
                    <input type="text" name="amel_no" class="tatc-input" placeholder="Kosongkan jika mengambil Basic License baru">
                </div>
                <div>
                    <label class="tatc-label">Password Akun LMS (Min. 8 Karakter - Standar IAM-03) *</label>
                    <input type="password" name="password" class="tatc-input" placeholder="Min. 8 karakter kombinasi huruf & angka" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="tatc-label">Pilih Program &amp; Batch Jadwal Pelatihan AMTO *</label>
                <select name="schedule_id" class="tatc-select" required>
                    <option value="">-- Pilih Batch &amp; Program Pelatihan --</option>
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
                    <span>Kirim Pendaftaran Peserta</span>
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
