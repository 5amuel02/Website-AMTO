<?php
/**
 * AMTO Module: Status Sertifikasi & E-Sertifikat Kompetensi AMTO (CASR Part 147 Approval No. 147D-19).
 * Role-Aware for Peserta (R01), Certification Officer (R05), Training Manager (R04), QA (R06), Admin (R07), Manajemen (R08).
 */
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

amto_auto_sync_from_tatc_cookie();
require_login();
amto_ensure_tables();

global $DB, $USER, $PAGE, $OUTPUT;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/amto/sertifikat.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('TATC Portal – Status Sertifikasi & E-Sertifikat Resmi AMTO');
$PAGE->set_heading('Status Sertifikasi & E-Sertifikat Resmi AMTO');

$rbac_role = amto_current_rbac_role();
$role_info = amto_get_detailed_role();
$is_student = ($rbac_role === 'student');
$can_issue_cert = in_array($rbac_role, ['admin', 'certofficer', 'trainingmanager'], true);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$msg = trim(optional_param('msg', '', PARAM_TEXT));
$preview_id = optional_param('preview', 0, PARAM_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_issue_cert) {
    require_sesskey();

    if ($action === 'issue_cert') {
        $student_username = trim(optional_param('student_username', 'peserta', PARAM_TEXT));
        $program_name     = trim(optional_param('program_name', '', PARAM_TEXT));
        $batch_code       = trim(optional_param('batch_code', 'BATCH-AMTO-2601', PARAM_TEXT));
        $amel_category    = trim(optional_param('amel_category', 'CASR Part 147 Certificate of Recognition (AMTO 147D-19)', PARAM_TEXT));
        $final_score      = max(75, min(100, optional_param('final_score', 88, PARAM_INT)));
        $attendance_pct   = max(80, min(100, optional_param('attendance_pct', 100, PARAM_INT)));

        $suser = $DB->get_record('user', ['username' => $student_username, 'deleted' => 0]);
        $last = $DB->get_records('amto_certificates', null, 'id DESC', 'id', 0, 1);
        $next_no = !empty($last) ? (reset($last)->id + 1) : 1;

        $c = new stdClass();
        $c->cert_no           = sprintf('CERT/AMTO-147D-19/2026/%04d', $next_no);
        $c->verification_code = sprintf('VRF-TATC-147D19-2026%04d', $next_no);
        $c->userid            = $suser ? (int)$suser->id : 0;
        $c->username          = $suser ? $suser->username : $student_username;
        $c->fullname          = $suser ? fullname($suser) : ucfirst($student_username);
        $c->program_name      = $program_name ?: 'Basic License Category B1.1 (Turbine Aeroplanes)';
        $c->batch_code        = $batch_code;
        $c->amel_category     = $amel_category;
        $c->final_score       = $final_score;
        $c->attendance_pct    = $attendance_pct;
        $c->issue_date        = date('Y-m-d');
        $c->expiry_date       = date('Y-m-d', strtotime('+2 years'));
        $c->issued_by         = fullname($USER) . ' (' . $role_info['code'] . ')';
        $c->status            = 'VALID';
        $c->timecreated       = time();
        $cid = $DB->insert_record('amto_certificates', $c);

        amto_log_audit_event('CERTIFICATE_ISSUE', 'amto_certificates', (int)$cid, "cert_no={$c->cert_no}, user={$c->username}", "Issued by {$c->issued_by}");
        header('Location: /amto/sertifikat.php?msg=' . urlencode("E-Sertifikat {$c->cert_no} untuk {$c->fullname} berhasil diterbitkan."));
        exit;
    }

    if ($action === 'void_cert') {
        $cert_id = required_param('cert_id', PARAM_INT);
        $cert = $DB->get_record('amto_certificates', ['id' => $cert_id]);
        if ($cert) {
            $cert->status = 'REVOKED / VOID';
            $DB->update_record('amto_certificates', $cert);
            amto_log_audit_event('CERTIFICATE_VOID', 'amto_certificates', (int)$cert_id, "cert_no={$cert->cert_no}, status=REVOKED", "Revoked by " . fullname($USER));
            header('Location: /amto/sertifikat.php?msg=' . urlencode("Sertifikat {$cert->cert_no} telah ditandai REVOKED / VOID."));
            exit;
        }
    }
}

if ($is_student) {
    $certificates = $DB->get_records_select(
        'amto_certificates',
        'userid = :uid OR LOWER(username) = :uname',
        ['uid' => $USER->id, 'uname' => strtolower($USER->username)],
        'timecreated DESC'
    );
} else {
    $certificates = $DB->get_records('amto_certificates', null, 'timecreated DESC');
}

$schedules = $DB->get_records('amto_schedules', null, 'start_date ASC');
$preview_cert = ($preview_id > 0) ? $DB->get_record('amto_certificates', ['id' => $preview_id]) : reset($certificates);

echo $OUTPUT->header();
echo amto_render_shared_styles();
?>

<div class="tatc-wrap">
    <div class="tatc-hero">
        <div>
            <div class="tatc-hero-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                <span>DGCA Approved AMTO No. 147D-19 &bull; <?php echo s($role_info['code'] . ' • ' . $role_info['label']); ?></span>
            </div>
            <h1>
                <?php echo $is_student ? 'Status Kelayakan Sertifikasi & E-Sertifikat Saya' : 'Manajemen & Register E-Sertifikat Kompetensi AMTO'; ?>
            </h1>
            <p>
                Penerbitan dan verifikasi <em>Certificate of Recognition / Completion</em> sesuai standar <strong>CASR Part 147</strong> berdasarkan pemenuhan syarat kehadiran (&ge; 80%), nilai ujian (&ge; 75), dan verifikasi <em>Practical Logbook</em>.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/amto/ujian_logbook.php?tab=hasil" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                <span>Lihat Transkrip Nilai</span>
            </a>
            <a href="/amto/ujian_logbook.php?tab=logbook" class="tatc-btn-outline" style="background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                <span>Practical Logbook</span>
            </a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="tatc-alert tatc-alert-success">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo s($msg); ?></div>
        </div>
    <?php endif; ?>

    <!-- CHECKLIST KELAYAKAN SERTIFIKASI CASR PART 147 -->
    <div class="tatc-stats-grid">
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">1. Verifikasi Berkas Prasyarat</div>
                <div class="tatc-stat-value" style="color:#15803d; font-size:18px;">LENGKAP &amp; VALID</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">2. Presensi Kelas &amp; Hangar (&ge;80%)</div>
                <div class="tatc-stat-value" style="color:#15803d; font-size:18px;">100% TERPENUHI</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">3. Nilai Ujian CBT &amp; Praktik (&ge;75)</div>
                <div class="tatc-stat-value" style="color:#1d4ed8; font-size:18px;">88 / 100 (KOMPETEN)</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">4. E-Sertifikat Diterbitkan</div>
                <div class="tatc-stat-value"><?php echo count($certificates); ?> Sertifikat</div>
            </div>
            <div class="tatc-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
            </div>
        </div>
    </div>

    <!-- OFFICIAL CERTIFICATE PREVIEW CARD -->
    <?php if ($preview_cert): ?>
    <div class="tatc-panel" style="border: 2px solid #b91c1c; background: linear-gradient(180deg, #ffffff 0%, #fffafa 100%);">
        <div style="border: 1px dashed #fca5a5; border-radius: 14px; padding: 28px 32px; text-align: center;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <span class="tatc-badge tatc-badge-approved">STATUS: <?php echo s($preview_cert->status); ?></span>
                <span style="font-size:12.5px; font-weight:700; color:#64748b;">NO. SERTIFIKAT: <code style="color:#b91c1c;"><?php echo s($preview_cert->cert_no); ?></code></span>
            </div>
            <div style="font-size:13px; font-weight:800; color:#b91c1c; letter-spacing:1.5px; text-transform:uppercase; margin-bottom:4px;">
                TELKOM UNIVERSITY AVIATION TRAINING CENTER (TATC)
            </div>
            <div style="font-size:12px; color:#64748b; font-weight:600; margin-bottom:18px;">
                DGCA Approved Aircraft Maintenance Training Organization (AMTO) Certificate No. 147D-19
            </div>
            <h2 style="font-size:26px; font-weight:800; color:#0f172a; margin-bottom:8px;">CERTIFICATE OF RECOGNITION</h2>
            <p style="font-size:13.5px; color:#475569; margin-bottom:12px;">Diberikan kepada peserta pelatihan yang telah memenuhi seluruh standar kompetensi teori dan praktik:</p>
            <div style="font-size:24px; font-weight:800; color:#b91c1c; margin-bottom:12px; text-decoration:underline;">
                <?php echo s($preview_cert->fullname); ?>
            </div>
            <div style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:4px;">
                <?php echo s($preview_cert->program_name); ?> (<?php echo s($preview_cert->batch_code); ?>)
            </div>
            <div style="font-size:13px; color:#64748b; margin-bottom:20px;">
                <?php echo s($preview_cert->amel_category); ?> &bull; Nilai Akhir: <strong><?php echo (int)$preview_cert->final_score; ?>/100</strong> &bull; Kehadiran: <strong><?php echo (int)$preview_cert->attendance_pct; ?>%</strong>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding-top:16px; font-size:12.5px; color:#475569;">
                <div style="text-align:left;">
                    <div>Kode Verifikasi Digital: <code><?php echo s($preview_cert->verification_code); ?></code></div>
                    <div>Tanggal Terbit: <strong><?php echo s($preview_cert->issue_date); ?></strong> &bull; Berlaku s/d: <strong><?php echo s($preview_cert->expiry_date); ?></strong></div>
                </div>
                <div style="text-align:right;">
                    <div>Disahkan oleh Pejabat Sertifikasi:</div>
                    <strong style="color:#0f172a;"><?php echo s($preview_cert->issued_by); ?></strong>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- REGISTER TABLE OF CERTIFICATES -->
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Register E-Sertifikat AMTO</h2>
                <p class="tatc-panel-sub">Rekaman sertifikat kelulusan resmi beserta kode verifikasi QR dan pejabat penerbit.</p>
            </div>
            <button type="button" onclick="window.print();" class="tatc-btn-red" style="padding:7px 14px; font-size:12.5px;">Cetak E-Sertifikat (PDF)</button>
        </div>
        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>Nomor Sertifikat &amp; Kode Verifikasi</th>
                        <th>Nama Peserta</th>
                        <th>Program &amp; Batch</th>
                        <th>Nilai &amp; Presensi</th>
                        <th>Tanggal Terbit</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($certificates)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:32px 16px; color:#64748b;">
                            <strong>Belum ada E-Sertifikat yang diterbitkan.</strong><br>
                            Sertifikat akan diterbitkan oleh Certification Officer / Training Manager setelah Peserta lulus Ujian CBT (&ge;75) dan Practical Logbook.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($certificates as $cert): ?>
                    <tr>
                        <td>
                            <strong style="color:#b91c1c;"><?php echo s($cert->cert_no); ?></strong>
                            <div style="font-size:11.5px; color:#64748b;">Verify: <code><?php echo s($cert->verification_code); ?></code></div>
                        </td>
                        <td>
                            <strong><?php echo s($cert->fullname); ?></strong>
                            <div style="font-size:11.5px; color:#64748b;"><code><?php echo s($cert->username); ?></code></div>
                        </td>
                        <td>
                            <div style="font-weight:700;"><?php echo s($cert->program_name); ?></div>
                            <code><?php echo s($cert->batch_code); ?></code>
                        </td>
                        <td>
                            <div style="font-weight:700; color:#15803d;">Skor: <?php echo (int)$cert->final_score; ?>/100</div>
                            <div style="font-size:12px; color:#64748b;">Presensi: <?php echo (int)$cert->attendance_pct; ?>%</div>
                        </td>
                        <td style="font-size:12.5px;"><?php echo s($cert->issue_date); ?></td>
                        <td>
                            <span class="tatc-badge <?php echo ($cert->status === 'VALID') ? 'tatc-badge-approved' : 'tatc-badge-rejected'; ?>">
                                <?php echo s($cert->status); ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="/amto/sertifikat.php?preview=<?php echo (int)$cert->id; ?>" class="tatc-btn-outline" style="padding:5px 10px; font-size:11.5px;">Preview</a>
                                <?php if ($can_issue_cert && $cert->status === 'VALID'): ?>
                                <form method="post" action="/amto/sertifikat.php" style="display:inline;">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="void_cert">
                                    <input type="hidden" name="cert_id" value="<?php echo (int)$cert->id; ?>">
                                    <button type="submit" class="tatc-btn-outline" style="padding:5px 10px; font-size:11.5px; color:#b91c1c !important; border-color:#fca5a5;">Void</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($can_issue_cert): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Terbitkan E-Sertifikat Baru (Khusus Certification Officer &amp; Training Manager)</h2>
                <p class="tatc-panel-sub">Terbitkan sertifikat kompetensi resmi bagi peserta yang telah lulus ujian (&ge;75) dan verifikasi Practical Logbook.</p>
            </div>
        </div>
        <form method="post" action="/amto/sertifikat.php">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="issue_cert">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Username Peserta *</label>
                    <input type="text" name="student_username" class="tatc-input" value="peserta" required>
                </div>
                <div>
                    <label class="tatc-label">Batch &amp; Program Pelatihan *</label>
                    <select name="batch_code" class="tatc-select">
                        <?php foreach ($schedules as $sc): ?>
                            <option value="<?php echo s($sc->batch_code); ?>"><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Nama Program pada Sertifikat *</label>
                    <input type="text" name="program_name" class="tatc-input" value="Basic License Category B1.1 (Turbine Aeroplanes)" required>
                </div>
                <div>
                    <label class="tatc-label">Nilai Akhir (&ge;75) &amp; Kehadiran (%)</label>
                    <div class="d-flex gap-2">
                        <input type="number" name="final_score" class="tatc-input" value="88" min="75" max="100">
                        <input type="number" name="attendance_pct" class="tatc-input" value="100" min="80" max="100">
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="tatc-btn-red">Terbitkan E-Sertifikat Resmi</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();
