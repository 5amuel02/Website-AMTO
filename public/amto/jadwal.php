<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

amto_ensure_tables();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/amto/jadwal.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('TATC Portal – Program & Jadwal Pelatihan AMTO');
$PAGE->set_heading('Program & Jadwal Pelatihan AMTO');

$role = amto_current_role();
$rbac_role = amto_current_rbac_role();
$can_manage_sched = in_array($rbac_role, ['admin', 'trainingmanager'], true);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$cat_filter = optional_param('cat', 'all', PARAM_TEXT);
$notice_msg = '';
$notice_type = 'success';

// 1. Handle Admin Actions: Add Schedule / Update Status / Soft-Delete (Void) Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage_sched) {
    // [SEC-FIX-02] APP-01 CSRF Protection via Moodle sesskey validation
    require_sesskey();

    if ($action === 'add_schedule') {
        $rec = new stdClass();
        $rec->batch_code = trim(optional_param('batch_code', '', PARAM_TEXT));
        $rec->program_name = trim(optional_param('program_name', '', PARAM_TEXT));
        $rec->category_type = trim(optional_param('category_type', 'Basic License', PARAM_TEXT));
        $rec->training_mode = trim(optional_param('training_mode', 'Blended (Theory & Practical)', PARAM_TEXT));
        $rec->room_location = trim(optional_param('room_location', '', PARAM_TEXT));
        $rec->instructor_name = trim(optional_param('instructor_name', '', PARAM_TEXT));
        $rec->start_date = trim(optional_param('start_date', '', PARAM_TEXT));
        $rec->end_date = trim(optional_param('end_date', '', PARAM_TEXT));
        $rec->schedule_time = trim(optional_param('schedule_time', 'Senin - Jumat, 08:00 - 16:00 WIB', PARAM_TEXT));
        $rec->quota = max(1, optional_param('quota', 24, PARAM_INT));
        $rec->status = trim(optional_param('status', 'Open Registration', PARAM_TEXT));
        $rec->timecreated = time();

        if ($rec->batch_code !== '' && $rec->program_name !== '') {
            $new_sched_id = $DB->insert_record('amto_schedules', $rec);
            if (function_exists('amto_sync_cross_db')) {
                amto_sync_cross_db(true);
            }
            if (function_exists('amto_log_audit_event')) {
                amto_log_audit_event('SCHEDULE_CREATE', 'amto_schedules', (int)$new_sched_id, "batch={$rec->batch_code}", "Created training schedule {$rec->program_name} ({$rec->start_date} - {$rec->end_date})");
            }

            // Sync to Moodle Calendar (mdl_event)
            $ev = new stdClass();
            $ev->name = '[' . $rec->batch_code . '] ' . $rec->program_name;
            $ev->description = 'Lokasi: ' . $rec->room_location . ' | Instruktur: ' . $rec->instructor_name . ' | Jam: ' . $rec->schedule_time;
            $ev->format = FORMAT_HTML;
            $ev->courseid = SITEID;
            $ev->groupid = 0;
            $ev->userid = $USER->id;
            $ev->modulename = '';
            $ev->instance = 0;
            $ev->eventtype = 'site';
            $ev->timestart = strtotime($rec->start_date ?: 'today');
            $ev->timeduration = 0;
            $ev->visible = 1;
            $ev->timemodified = time();
            $DB->insert_record('event', $ev);

            // [SEC-FIX-06] APP-01 Output encoding with s() to prevent Cross-Site Scripting (XSS)
            $safe_batch = s($rec->batch_code);
            $safe_prog  = s($rec->program_name);
            $notice_msg = "Jadwal Pelatihan <strong>{$safe_batch} – {$safe_prog}</strong> berhasil ditambahkan dan disinkronkan ke Kalender Moodle.";
        } else {
            $notice_msg = 'Mohon isi Kode Batch dan Nama Program Pelatihan.';
            $notice_type = 'danger';
        }
    } else if ($action === 'update_status') {
        $sched_id = required_param('sched_id', PARAM_INT);
        $new_status = required_param('new_status', PARAM_TEXT);
        $sched = $DB->get_record('amto_schedules', ['id' => $sched_id]);
        if ($sched) {
            $old_st = $sched->status;
            $sched->status = $new_status;
            $DB->update_record('amto_schedules', $sched);
            if (function_exists('amto_log_audit_event')) {
                amto_log_audit_event('SCHEDULE_STATUS_UPDATE', 'amto_schedules', $sched_id, "status: {$old_st} -> {$new_status}", "Updated batch {$sched->batch_code} status");
            }
            // [SEC-FIX-06] APP-01 Output encoding with s() to prevent Cross-Site Scripting (XSS)
            $safe_batch  = s($sched->batch_code);
            $safe_status = s($new_status);
            $notice_msg  = "Status jadwal <strong>{$safe_batch}</strong> diperbarui menjadi <strong>{$safe_status}</strong>.";
        }
    } else if ($action === 'delete_schedule') {
        $sched_id = required_param('sched_id', PARAM_INT);
        $sched = $DB->get_record('amto_schedules', ['id' => $sched_id]);
        if ($sched) {
            // [SEC-FIX-04] SEC-05 Soft-Delete / Void instead of Hard Delete
            $sched->status = 'Voided';
            $DB->update_record('amto_schedules', $sched);
            if (function_exists('amto_log_audit_event')) {
                amto_log_audit_event('SCHEDULE_VOID', 'amto_schedules', $sched_id, 'status=Voided', 'Schedule voided by ' . fullname($USER));
            }
        }
        $notice_msg = 'Jadwal pelatihan telah ditandai VOID (Soft-Delete SEC-05) tanpa menghapus jejak histori.';
        $notice_type = 'warning';
    }
}

$schedules = $DB->get_records('amto_schedules', null, 'start_date ASC');
$total_schedules = count($schedules);
$open_schedules = 0;
$ongoing_schedules = 0;
foreach ($schedules as $s) {
    if ($s->status === 'Open Registration') {
        $open_schedules++;
    } else if ($s->status === 'Ongoing') {
        $ongoing_schedules++;
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
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>CASR Part 147 &bull; Training Batch, Classroom &amp; Hangar Schedule</span>
            </div>
            <h1>
                <?php
                if ($role === 'admin') {
                    echo 'Manajemen Program, Batch & Jadwal Pelatihan';
                } else if ($role === 'instructor') {
                    echo 'Jadwal Mengajar & Penugasan Instruktur AMTO';
                } else {
                    echo 'Jadwal Pelatihan & Batch TATC – AMTO';
                }
                ?>
            </h1>
            <p>
                <?php if ($role === 'admin'): ?>
                    Kelola jadwal kelas teori (Classroom) dan praktik (Hangar/Workshop), plotting instruktur, kuota peserta per batch, serta sinkronisasi kalender akademik AMTO.
                <?php elseif ($role === 'instructor'): ?>
                    Daftar jadwal kelas penugasan mengajar teori dan praktik hangar beserta lokasi ruangan, jam sesi, dan daftar peserta per batch.
                <?php else: ?>
                    Informasi jadwal pelaksanaan pelatihan Basic License (A1–A4, B1, B2), Aircraft Type Rating, dan Mandatory Training beserta lokasi Ruangan/Hangar dan Instruktur.
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/calendar/view.php?view=month" class="tatc-btn-outline" style="background: rgba(255,255,255,0.14); color:#fff !important; border-color: rgba(255,255,255,0.3);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>Buka Kalender Bulanan</span>
            </a>
            <?php if ($role === 'admin'): ?>
            <button type="button" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;" onclick="var f=document.getElementById('tatcAddSchedPanel'); f.style.display = (f.style.display==='none' ? 'block' : 'none');">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Tambah Jadwal Batch Baru</span>
            </button>
            <?php elseif ($role === 'student' && isloggedin() && !isguestuser()): ?>
            <a href="/amto/registrasi.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                <span>Data &amp; Status Registrasi Saya</span>
            </a>
            <?php else: ?>
            <a href="/amto/registrasi.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                <span>Form Registrasi Peserta Baru</span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($notice_msg !== ''): ?>
        <div class="alert alert-<?php echo $notice_type; ?> mb-4" style="border-radius:12px; font-size:14px;">
            <?php echo $notice_msg; ?>
        </div>
    <?php endif; ?>

    <!-- SUMMARY STATS -->
    <div class="tatc-stats-grid">
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Total Batch Terjadwal</div>
                <div class="tatc-stat-value"><?php echo $total_schedules; ?> Batch</div>
            </div>
            <div class="tatc-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Pendaftaran Dibuka (Open)</div>
                <div class="tatc-stat-value" style="color:#15803d;"><?php echo $open_schedules; ?> Batch</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Pelatihan Berjalan (Ongoing)</div>
                <div class="tatc-stat-value" style="color:#1d4ed8;"><?php echo $ongoing_schedules; ?> Batch</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            </div>
        </div>
    </div>

    <?php if ($role === 'admin'): ?>
    <!-- FORM TAMBAH JADWAL BARU (ADMIN) -->
    <div id="tatcAddSchedPanel" class="tatc-panel" style="display:none;">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Buat Jadwal &amp; Batch Pelatihan AMTO Baru</h2>
                <p class="tatc-panel-sub">Jadwal yang ditambahkan otomatis muncul di halaman pendaftaran peserta dan disinkronkan ke Kalender Moodle.</p>
            </div>
        </div>
        <form method="post" action="/amto/jadwal.php">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add_schedule">
            <div class="tatc-form-grid mb-3">
                <div>
                    <label class="tatc-label">Kode Batch *</label>
                    <input type="text" name="batch_code" class="tatc-input" placeholder="Contoh: BATCH-AMTO-2605" required>
                </div>
                <div>
                    <label class="tatc-label">Nama Program Pelatihan *</label>
                    <input type="text" name="program_name" class="tatc-input" placeholder="Contoh: Basic License Category A1 (Turbine)" required>
                </div>
                <div>
                    <label class="tatc-label">Kategori Program (CASR Part 147) *</label>
                    <select name="category_type" class="tatc-select">
                        <option value="Basic License">Basic License (Cat A / B1 / B2)</option>
                        <option value="Type Rating">Aircraft Type Rating</option>
                        <option value="Mandatory / Recurrent">Mandatory &amp; Recurrent Training</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Metode Pelaksanaan *</label>
                    <select name="training_mode" class="tatc-select">
                        <option value="Blended (Theory & Practical)">Blended (Theory Classroom &amp; Practical Hangar)</option>
                        <option value="Theory (Classroom / Online LMS)">Theory (Classroom / Online LMS)</option>
                        <option value="Practical (Hangar / Workshop)">Practical (Hangar / Workshop)</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Lokasi Ruangan / Hangar *</label>
                    <input type="text" name="room_location" class="tatc-input" placeholder="Contoh: Classroom A-202 & Hangar B-01" required>
                </div>
                <div>
                    <label class="tatc-label">Instruktur Penanggung Jawab *</label>
                    <input type="text" name="instructor_name" class="tatc-input" placeholder="Contoh: Ir. Budi Santoso, AMEL" required>
                </div>
                <div>
                    <label class="tatc-label">Tanggal Mulai *</label>
                    <input type="date" name="start_date" class="tatc-input" required value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                </div>
                <div>
                    <label class="tatc-label">Tanggal Selesai *</label>
                    <input type="date" name="end_date" class="tatc-input" required value="<?php echo date('Y-m-d', strtotime('+37 days')); ?>">
                </div>
                <div>
                    <label class="tatc-label">Hari &amp; Jam Sesi Pelatihan *</label>
                    <input type="text" name="schedule_time" class="tatc-input" value="Senin - Jumat, 08:00 - 16:00 WIB" required>
                </div>
                <div>
                    <label class="tatc-label">Kuota Maksimal Peserta *</label>
                    <input type="number" name="quota" class="tatc-input" value="24" min="1" max="200" required>
                </div>
                <div>
                    <label class="tatc-label">Status Pendaftaran *</label>
                    <select name="status" class="tatc-select">
                        <option value="Open Registration">Open Registration</option>
                        <option value="Ongoing">Ongoing</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="tatc-btn-outline" onclick="document.getElementById('tatcAddSchedPanel').style.display='none';">Batal</button>
                <button type="submit" class="tatc-btn-red">Simpan Jadwal Pelatihan</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- TABEL JADWAL PELATIHAN -->
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Jadwal Pelatihan, Ruangan &amp; Instruktur</h2>
                <p class="tatc-panel-sub">Filter berdasarkan kategori lisensi untuk melihat detail jadwal pelaksanaan pelatihan.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="/amto/jadwal.php?cat=all" class="<?php echo ($cat_filter === 'all') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:7px 14px; font-size:12.5px;">Semua Kategori</a>
                <a href="/amto/jadwal.php?cat=Basic License" class="<?php echo ($cat_filter === 'Basic License') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:7px 14px; font-size:12.5px;">Basic License</a>
                <a href="/amto/jadwal.php?cat=Type Rating" class="<?php echo ($cat_filter === 'Type Rating') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:7px 14px; font-size:12.5px;">Type Rating</a>
                <a href="/amto/jadwal.php?cat=Mandatory / Recurrent" class="<?php echo ($cat_filter === 'Mandatory / Recurrent') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:7px 14px; font-size:12.5px;">Mandatory</a>
            </div>
        </div>

        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>Kode Batch &amp; Kategori</th>
                        <th>Program Pelatihan &amp; Metode</th>
                        <th>Ruangan / Hangar &amp; Instruktur</th>
                        <th>Periode &amp; Jam Sesi</th>
                        <th>Kuota Peserta</th>
                        <th>Status</th>
                        <th>Aksi / Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $s): ?>
                        <?php
                        if ($cat_filter !== 'all' && $s->category_type !== $cat_filter) {
                            continue;
                        }
                        $reg_count = $DB->count_records('amto_registrations', ['schedule_id' => $s->id]);
                        $is_my_batch = false;
                        if ($role === 'student' && isloggedin() && !isguestuser()) {
                            $is_my_batch = $DB->record_exists_select(
                                'amto_registrations',
                                'schedule_id = :sid AND (userid = :uid OR username = :uname)',
                                ['sid' => $s->id, 'uid' => $USER->id, 'uname' => $USER->username]
                            );
                        }
                        ?>
                        <tr>
                            <td>
                                <strong style="color:#b91c1c; font-size:13px;"><?php echo s($s->batch_code); ?></strong>
                                <div style="margin-top:4px;">
                                    <span class="tatc-doc-pill" style="margin:0;"><?php echo s($s->category_type); ?></span>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight:700; color:#0f172a; font-size:14px;"><?php echo s($s->program_name); ?></div>
                                <div style="font-size:12px; color:#64748b; margin-top:2px;"><?php echo s($s->training_mode); ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600; color:#1e293b;"><?php echo s($s->room_location); ?></div>
                                <div style="font-size:12px; color:#64748b; margin-top:2px;">Instruktur: <strong><?php echo s($s->instructor_name); ?></strong></div>
                            </td>
                            <td>
                                <div style="font-weight:600; color:#0f172a;"><?php echo s($s->start_date); ?> s/d <?php echo s($s->end_date); ?></div>
                                <div style="font-size:12px; color:#64748b; margin-top:2px;"><?php echo s($s->schedule_time); ?></div>
                            </td>
                            <td>
                                <div style="font-weight:700; color:#0f172a;"><?php echo $reg_count; ?> / <?php echo (int)$s->quota; ?> Peserta</div>
                                <div style="font-size:11.5px; color:#64748b;">Sisa <?php echo max(0, (int)$s->quota - $reg_count); ?> kursi</div>
                            </td>
                            <td>
                                <?php if ($s->status === 'Open Registration'): ?>
                                    <span class="tatc-badge tatc-badge-open">Open Registration</span>
                                <?php elseif ($s->status === 'Ongoing'): ?>
                                    <span class="tatc-badge tatc-badge-ongoing">Ongoing</span>
                                <?php else: ?>
                                    <span class="tatc-badge tatc-badge-pending"><?php echo s($s->status); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($role === 'admin'): ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <a href="/amto/registrasi.php?batch=<?php echo urlencode($s->batch_code); ?>" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px;">
                                            Lihat Pendaftar (<?php echo $reg_count; ?>)
                                        </a>
                                        <form method="post" action="/amto/jadwal.php" style="display:inline;" onsubmit="return confirm('Hapus jadwal batch ini?');">
                                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                            <input type="hidden" name="action" value="delete_schedule">
                                            <input type="hidden" name="sched_id" value="<?php echo $s->id; ?>">
                                            <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#b91c1c !important; border-color:#fecaca;">Hapus</button>
                                        </form>
                                    </div>
                                <?php elseif ($role === 'instructor'): ?>
                                    <a href="/course/index.php" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;">
                                        Buka Materi Kelas
                                    </a>
                                <?php elseif ($role === 'student' && isloggedin() && !isguestuser()): ?>
                                    <?php if ($is_my_batch): ?>
                                        <span class="tatc-badge tatc-badge-approved">Batch Pelatihan Saya</span>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:#64748b;">Jadwal Batch Lain</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($s->status === 'Open Registration'): ?>
                                        <a href="/amto/registrasi.php?schedule_id=<?php echo $s->id; ?>" class="tatc-btn-red" style="padding:7px 14px; font-size:12.5px;">
                                            Daftar Batch Ini
                                        </a>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:#94a3b8; font-weight:600;">Pendaftaran Tutup</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
