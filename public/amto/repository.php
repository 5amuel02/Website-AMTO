<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
amto_ensure_tables();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/amto/repository.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('TATC Portal – LMS Repository & Modul Materi AMTO');
$PAGE->set_heading('LMS Repository & Modul Materi Pelatihan');

$role = amto_current_role();
$can_manage = in_array($role, ['admin', 'instructor'], true);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$cat_filter = optional_param('cat', 'all', PARAM_TEXT);
$search_q = trim(optional_param('q', '', PARAM_TEXT));
$msg = optional_param('msg', '', PARAM_TEXT);
$notice_msg = $msg !== '' ? s($msg) : '';
$notice_type = 'success';

// Helper to handle secure repository file upload with MIME validation & SHA-256 Checksum (DP-06, DP-09, APP-06)
function amto_save_repo_file(string $modcode): array {
    if (empty($_FILES['repo_file']['name']) || $_FILES['repo_file']['error'] !== UPLOAD_ERR_OK) {
        return ['path' => '', 'size' => '', 'hash' => ''];
    }
    $allowed_ext = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'mp4', 'zip', 'png', 'jpg'];
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

// Handle Upload / Delete Actions (for Admin & Instructor only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    // [SEC-FIX-02] APP-01 CSRF Protection via Moodle sesskey validation
    require_sesskey();

    if ($action === 'upload_module') {
        $module_code = strtoupper(trim(optional_param('module_code', '', PARAM_TEXT)));
        $title = trim(optional_param('title', '', PARAM_TEXT));
        $category_type = trim(optional_param('category_type', 'Basic License Cat B1', PARAM_TEXT));
        $batch_code = trim(optional_param('batch_code', 'Semua Batch', PARAM_TEXT));
        $file_type = trim(optional_param('file_type', 'PDF Training Manual', PARAM_TEXT));
        $doc_version = trim(optional_param('doc_version', 'Rev. 01 - 2026', PARAM_TEXT));
        $description = trim(optional_param('description', '', PARAM_TEXT));

        if ($module_code !== '' && $title !== '') {
            $upload_res = amto_save_repo_file($module_code);

            $rec = new stdClass();
            $rec->module_code = $module_code;
            $rec->title = $title;
            $rec->category_type = $category_type;
            $rec->batch_code = $batch_code ?: 'Semua Batch';
            $rec->file_type = $file_type;
            $rec->file_path = $upload_res['path'];
            $rec->file_size = $upload_res['size'] ?: 'Dokumen Digital';
            $rec->file_hash = $upload_res['hash'] ?? '';
            $rec->doc_version = $doc_version ?: 'Rev. 01 - 2026';
            $rec->description = $description;
            $rec->uploaded_by = $USER->id;
            $rec->uploader_name = fullname($USER);
            $rec->status = 'Published';
            $rec->timecreated = time();

            $DB->insert_record('amto_repository', $rec);

            $m = "Modul [{$module_code}] {$title} berhasil ditambahkan ke LMS Repository AMTO.";
            header('Location: /amto/repository.php?msg=' . urlencode($m));
            exit;
        } else {
            $notice_msg = 'Mohon lengkapi Kode Modul dan Judul Materi.';
            $notice_type = 'danger';
        }
    } else if ($action === 'delete_module') {
        $mod_id = required_param('mod_id', PARAM_INT);
        $mod = $DB->get_record('amto_repository', ['id' => $mod_id]);
        if ($mod) {
            $DB->delete_records('amto_repository', ['id' => $mod_id]);
            $m = "Modul [{$mod->module_code}] {$mod->title} berhasil dihapus dari Repository.";
            header('Location: /amto/repository.php?msg=' . urlencode($m));
            exit;
        }
    }
}

$all_modules = $DB->get_records('amto_repository', null, 'timecreated DESC');
$schedules = $DB->get_records('amto_schedules', null, 'start_date ASC');

$total_modules = count($all_modules);
$basic_count = 0;
$type_count = 0;
$mandatory_count = 0;
foreach ($all_modules as $m) {
    if (stripos($m->category_type, 'Basic License') !== false) {
        $basic_count++;
    } else if (stripos($m->category_type, 'Type Rating') !== false) {
        $type_count++;
    } else {
        $mandatory_count++;
    }
}

// Filter modules
$visible_modules = [];
foreach ($all_modules as $m) {
    if ($cat_filter !== 'all' && stripos($m->category_type, $cat_filter) === false) {
        continue;
    }
    if ($search_q !== '' && stripos($m->module_code, $search_q) === false && stripos($m->title, $search_q) === false && stripos((string)$m->description, $search_q) === false) {
        continue;
    }
    $visible_modules[] = $m;
}

echo $OUTPUT->header();
echo amto_render_shared_styles();
?>

<div class="tatc-wrap">
    <!-- HERO BANNER -->
    <div class="tatc-hero">
        <div>
            <div class="tatc-hero-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                <span>CASR Part 147 &bull; Central Training Manual &amp; Module Repository</span>
            </div>
            <h1>
                <?php
                if ($role === 'admin') {
                    echo 'Manajemen LMS Repository & Modul Materi AMTO';
                } else if ($role === 'instructor') {
                    echo 'Repository Bahan Ajar & Modul Instruktur AMTO';
                } else {
                    echo 'Katalog Materi & Modul Pelatihan Saya (LMS Repository)';
                }
                ?>
            </h1>
            <p>
                <?php if ($can_manage): ?>
                    Pusat penyimpanan terpadu Training Manual (MTPM), modul kurikulum CASR Part 147, slide presentasi pengajar, dan panduan praktik hangar dengan kontrol versi dokumen QA.
                <?php else: ?>
                    Akses dan unduh modul pelatihan resmi, buku Training Manual CASR Part 147, serta materi kelas sesuai program pelatihan yang Anda ikuti di TATC – AMTO.
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/course/index.php" class="tatc-btn-outline" style="background: rgba(255,255,255,0.14); color:#fff !important; border-color: rgba(255,255,255,0.3);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span>Struktur Kategori Kelas</span>
            </a>
            <?php if ($can_manage): ?>
            <button type="button" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;" onclick="var f=document.getElementById('tatcUploadRepoPanel'); f.style.display = (f.style.display==='none' ? 'block' : 'none');">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Unggah Modul / Materi Baru</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($notice_msg !== ''): ?>
        <div class="alert alert-<?php echo $notice_type; ?> mb-4" style="border-radius:12px; font-size:14px;">
            <?php echo $notice_msg; ?>
        </div>
    <?php endif; ?>

    <!-- KPI SUMMARY CARDS -->
    <div class="tatc-stats-grid">
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Total Modul Tersimpan</div>
                <div class="tatc-stat-value"><?php echo $total_modules; ?> Modul</div>
            </div>
            <div class="tatc-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Modul Basic License (A / B1 / B2)</div>
                <div class="tatc-stat-value" style="color:#15803d;"><?php echo $basic_count; ?> Modul</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Modul Aircraft Type Rating</div>
                <div class="tatc-stat-value" style="color:#1d4ed8;"><?php echo $type_count; ?> Modul</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Mandatory &amp; Recurrent (HF/SMS)</div>
                <div class="tatc-stat-value" style="color:#b91c1c;"><?php echo $mandatory_count; ?> Modul</div>
            </div>
            <div class="tatc-stat-icon" style="background:#fee2e2; color:#b91c1c;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
        </div>
    </div>

    <?php if ($can_manage): ?>
    <!-- FORM UNGGAH MODUL REPOSITORY BARU (ADMIN & INSTRUCTOR ONLY) -->
    <div id="tatcUploadRepoPanel" class="tatc-panel" style="display:none;">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Unggah Modul &amp; Materi Pelatihan ke LMS Repository</h2>
                <p class="tatc-panel-sub">Dokumen yang diunggah akan tercatat di tabel <code>mdl_amto_repository</code> lengkap dengan nomor versi revisi QA dan pengunggah.</p>
            </div>
            <span class="tatc-badge tatc-badge-open">Kontrol Dokumen CASR 147</span>
        </div>

        <form method="post" action="/amto/repository.php" enctype="multipart/form-data">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="upload_module">

            <div class="tatc-form-grid mb-3">
                <div>
                    <label class="tatc-label">Kode Modul CASR Part 147 *</label>
                    <input type="text" name="module_code" class="tatc-input" placeholder="Contoh: MOD-01 / MOD-09 / MOD-11A" required>
                </div>
                <div>
                    <label class="tatc-label">Judul Modul / Training Manual *</label>
                    <input type="text" name="title" class="tatc-input" placeholder="Contoh: Human Factors in Aviation Maintenance" required>
                </div>
                <div>
                    <label class="tatc-label">Kategori Kurikulum AMTO *</label>
                    <select name="category_type" class="tatc-select">
                        <option value="Basic License Cat A (A1–A4)">Basic License Category A (Line Maintenance A1–A4)</option>
                        <option value="Basic License Cat B1 (Turbine/Piston)">Basic License Category B1 (Turbine &amp; Piston)</option>
                        <option value="Basic License Cat B2 (Avionics)">Basic License Category B2 (Avionics Maintenance)</option>
                        <option value="Aircraft Type Rating">Aircraft Type Rating &amp; Specialized</option>
                        <option value="Mandatory & Recurrent">Mandatory &amp; Recurrent (Human Factors, SMS, EWIS)</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Target Batch Pelatihan *</label>
                    <select name="batch_code" class="tatc-select">
                        <option value="Semua Batch">Semua Batch (Terbuka untuk Kategori Ini)</option>
                        <?php foreach ($schedules as $s): ?>
                            <option value="<?php echo s($s->batch_code); ?>"><?php echo s($s->batch_code . ' – ' . $s->program_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Jenis Dokumen Materi *</label>
                    <select name="file_type" class="tatc-select">
                        <option value="PDF Training Manual">PDF Training Manual (MTPM)</option>
                        <option value="Slide Presentasi Kelas">Slide Presentasi Kelas (PPT/PDF)</option>
                        <option value="Panduan Praktik Hangar (Job Card)">Panduan Praktik Hangar (Task / Job Card)</option>
                        <option value="Silabus & Kurikulum">Silabus &amp; Kurikulum CASR Part 147</option>
                        <option value="Video Pembelajaran">Video Pembelajaran</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Nomor Revisi Dokumen (Standar QA) *</label>
                    <input type="text" name="doc_version" class="tatc-input" value="Rev. 01 - 2026" required>
                </div>
            </div>

            <div class="tatc-form-grid mb-4">
                <div>
                    <label class="tatc-label">Deskripsi Ringkas / Pokok Bahasan Modul</label>
                    <input type="text" name="description" class="tatc-input" placeholder="Contoh: Modul wajib CASR Part 147 Sub-module 9.1 s/d 9.9">
                </div>
                <div>
                    <label class="tatc-label">Pilih File Materi (PDF/PPTX/DOCX/MP4, Maks 25MB)</label>
                    <input type="file" name="repo_file" class="tatc-input" accept=".pdf,.ppt,.pptx,.doc,.docx,.mp4,.zip,.png,.jpg">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="tatc-btn-outline" onclick="document.getElementById('tatcUploadRepoPanel').style.display='none';">Batal</button>
                <button type="submit" class="tatc-btn-red">Simpan &amp; Terbitkan Modul</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- TABEL KATALOG REPOSITORY LMS -->
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Modul &amp; Bahan Ajar Repository AMTO</h2>
                <p class="tatc-panel-sub">Cari berdasarkan kode modul atau filter per kategori lisensi CASR Part 147.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <form method="get" action="/amto/repository.php" class="d-flex gap-2">
                    <input type="hidden" name="cat" value="<?php echo s($cat_filter); ?>">
                    <input type="text" name="q" class="tatc-input" style="min-width:210px; padding:7px 12px;" placeholder="Cari Kode / Judul Modul..." value="<?php echo s($search_q); ?>">
                    <button type="submit" class="tatc-btn-outline" style="padding:7px 14px;">Cari</button>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="/amto/repository.php?cat=all" class="<?php echo ($cat_filter === 'all') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Semua Kategori</a>
            <a href="/amto/repository.php?cat=Cat A" class="<?php echo ($cat_filter === 'Cat A') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Basic Cat A</a>
            <a href="/amto/repository.php?cat=Cat B1" class="<?php echo ($cat_filter === 'Cat B1') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Basic Cat B1</a>
            <a href="/amto/repository.php?cat=Cat B2" class="<?php echo ($cat_filter === 'Cat B2') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Basic Cat B2</a>
            <a href="/amto/repository.php?cat=Type Rating" class="<?php echo ($cat_filter === 'Type Rating') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Type Rating</a>
            <a href="/amto/repository.php?cat=Mandatory" class="<?php echo ($cat_filter === 'Mandatory') ? 'tatc-btn-red' : 'tatc-btn-outline'; ?>" style="padding:6px 13px; font-size:12px;">Mandatory</a>
        </div>

        <?php if (empty($visible_modules)): ?>
            <div class="text-center py-5">
                <p class="text-muted mb-1" style="font-size:15px;">Belum ada modul materi yang tersimpan di Repository LMS pada kategori ini.</p>
                <?php if ($can_manage): ?>
                    <p class="text-muted" style="font-size:13px;">Klik tombol <strong>"Unggah Modul / Materi Baru"</strong> di kanan atas untuk mulai mengunggah Training Manual atau bahan ajar.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="tatc-table-wrap">
                <table class="tatc-table">
                    <thead>
                        <tr>
                            <th>Kode Modul &amp; Revisi QA</th>
                            <th>Judul Materi &amp; Deskripsi</th>
                            <th>Kategori &amp; Target Batch</th>
                            <th>Jenis &amp; Ukuran File</th>
                            <th>Diunggah Oleh</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visible_modules as $m): ?>
                            <tr>
                                <td>
                                    <strong style="color:#b91c1c; font-size:13.5px;"><?php echo s($m->module_code); ?></strong>
                                    <div style="margin-top:4px;">
                                        <span class="tatc-badge tatc-badge-ongoing"><?php echo s($m->doc_version); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:700; color:#0f172a; font-size:14px;"><?php echo s($m->title); ?></div>
                                    <?php if (!empty($m->description)): ?>
                                        <div style="font-size:12px; color:#64748b; margin-top:2px; max-width:320px;"><?php echo s($m->description); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:#1e293b;"><?php echo s($m->category_type); ?></div>
                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;">Target: <?php echo s($m->batch_code); ?></div>
                                </td>
                                <td>
                                    <span class="tatc-doc-pill"><?php echo s($m->file_type); ?></span>
                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;"><?php echo s($m->file_size); ?></div>
                                    <?php if (!empty($m->file_hash)): ?>
                                        <div style="font-size:10.5px; color:#15803d; font-family:monospace; margin-top:2px;" title="SHA-256: <?php echo s($m->file_hash); ?>">
                                            ✓ SHA256: <?php echo s(substr($m->file_hash, 0, 10)); ?>...
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight:600; font-size:12.5px;"><?php echo s($m->uploader_name); ?></div>
                                    <div style="font-size:11.5px; color:#64748b;"><?php echo date('d M Y, H:i', (int)$m->timecreated); ?></div>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if (!empty($m->file_path)): ?>
                                            <a href="/amto/download.php?type=repo&amp;id=<?php echo (int)$m->id; ?>" target="_blank" class="tatc-btn-red" style="padding:6px 12px; font-size:12px;">
                                                Buka / Unduh
                                            </a>
                                        <?php else: ?>
                                            <span class="tatc-doc-pill">Terdaftar</span>
                                        <?php endif; ?>
                                        <?php if ($can_manage): ?>
                                            <form method="post" action="/amto/repository.php" style="display:inline;" onsubmit="return confirm('Hapus modul ini dari Repository?');">
                                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                <input type="hidden" name="action" value="delete_module">
                                                <input type="hidden" name="mod_id" value="<?php echo $m->id; ?>">
                                                <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#b91c1c !important; border-color:#fecaca;">
                                                    Hapus
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
echo $OUTPUT->footer();
