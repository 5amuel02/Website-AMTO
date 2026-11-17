<?php
define('CLI_SCRIPT', true);
require('C:/moodle/server/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/blocklib.php');

global $DB;

echo "1. Updating Custom Menu & Navigation...\n";
$custommenu = "Beranda Utama|/?redirect=0\nProgram & Kurikulum|/course/index.php\nJadwal Pelatihan|/calendar/view.php?view=month\nUI/UX Prototype AMTO|/amto/";
set_config('custommenuitems', $custommenu);

echo "2. Enrolling Admin into all 4 AMTO Courses & adding Course Cover Banners...\n";
$admin = get_admin();
$enrol_manual = enrol_get_plugin('manual');
$studentrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
if (!$studentrole) {
    $studentrole = $DB->get_record('role', ['shortname' => 'student']);
}

$banner_themes = [
    'AMTO-B1-M07' => ['#061326', '#0284c7', 'CASR PART 147 • BASIC B1.1', 'AIRFRAME & POWERPLANT'],
    'AMTO-B2-M05' => ['#1e1b4b', '#4f46e5', 'CASR PART 147 • BASIC B2', 'AVIONICS & INSTRUMENTS'],
    'AMTO-HF-M09' => ['#064e3b', '#0d9488', 'MANDATORY SAFETY TRAINING', 'HUMAN FACTORS & SMS'],
    'AMTO-REPO-01' => ['#451a03', '#d97706', 'CONTROLLED DOCUMENTS', 'AMTO LMS REPOSITORY'],
];

$fs = get_file_storage();
$courses = $DB->get_records_select('course', 'id > 1');

foreach ($courses as $course) {
    // Ensure manual enrolment instance exists and enroll admin
    $instances = enrol_get_instances($course->id, true);
    $manualinstance = null;
    foreach ($instances as $inst) {
        if ($inst->enrol === 'manual') {
            $manualinstance = $inst;
            break;
        }
    }
    if (!$manualinstance && $enrol_manual) {
        $instid = $enrol_manual->add_default_instance($course);
        $manualinstance = $DB->get_record('enrol', ['id' => $instid]);
    }
    if ($manualinstance && $enrol_manual) {
        if (!$DB->record_exists('user_enrolments', ['enrolid' => $manualinstance->id, 'userid' => $admin->id])) {
            $enrol_manual->enrol_user($manualinstance, $admin->id, $studentrole->id, time());
            echo "   - Enrolled Admin in: {$course->shortname}\n";
        }
    }

    // Add SVG Course Overview Banner Image
    $coursecontext = context_course::instance($course->id);
    $fs->delete_area_files($coursecontext->id, 'course', 'overviewfiles');

    $colors = $banner_themes[$course->shortname] ?? ['#0a1f3d', '#0284c7', 'AMTO TRAINING', $course->shortname];
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="400" viewBox="0 0 800 400">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$colors[0]}" />
      <stop offset="100%" stop-color="{$colors[1]}" />
    </linearGradient>
  </defs>
  <rect width="800" height="400" fill="url(#g)" />
  <circle cx="700" cy="80" r="180" fill="rgba(255,255,255,0.06)" />
  <circle cx="650" cy="340" r="140" fill="rgba(255,255,255,0.05)" />
  <rect x="48" y="52" width="280" height="36" rx="18" fill="rgba(255,255,255,0.16)" />
  <text x="68" y="76" fill="#38bdf8" font-family="Arial, sans-serif" font-size="15" font-weight="bold">{$colors[2]}</text>
  <text x="48" y="165" fill="#ffffff" font-family="Arial, sans-serif" font-size="38" font-weight="bold">{$colors[3]}</text>
  <text x="48" y="215" fill="#e2e8f0" font-family="Arial, sans-serif" font-size="22">{$course->shortname} • AMTO Certified Course</text>
  <rect x="48" y="290" width="190" height="44" rx="10" fill="#f59e0b" />
  <text x="72" y="318" fill="#061326" font-family="Arial, sans-serif" font-size="16" font-weight="bold">✈ AMTO PORTAL</text>
</svg>
SVG;

    $filerecord = [
        'contextid' => $coursecontext->id,
        'component' => 'course',
        'filearea'  => 'overviewfiles',
        'itemid'    => 0,
        'filepath'  => '/',
        'filename'  => strtolower($course->shortname) . '-banner.svg',
    ];
    $fs->create_file_from_string($filerecord, $svg);
}

echo "3. Adding AMTO Operational Dashboard Hero & KPI Cards directly to Dashboard (/my/)...\n";
$dashboard_html = <<<'HTML'
<div class="amto-live-hero">
  <div class="amto-live-hero-inner">
    <div class="amto-live-hero-left">
      <div class="amto-live-pill">✈️ PORTAL OPERASIONAL AMTO • CASR PART 147 &amp; PART 65</div>
      <h1>AMTO Integrated Training, Learning &amp; Certification System</h1>
      <p>Selamat datang di <strong>Dashboard Operasional AMTO</strong>. Kelola registrasi peserta (<em>Trainee</em>), jadwal kelas pelatihan, <em>LMS Repository</em> dokumen terkendali, evaluasi ujian, verifikasi <em>Practical Logbook</em>, serta register sertifikat berdasarkan matriks <strong>8 Role RBAC</strong>.</p>
      <div class="amto-live-actions">
        <a href="/amto/" class="amto-btn-gold">🎨 Buka Showcase UI/UX Lengkap (6 Layar)</a>
        <a href="/course/index.php" class="amto-btn-primary">📚 Katalog Program &amp; Repository</a>
        <a href="/calendar/view.php?view=month" class="amto-btn-outline">📅 Kalender Jadwal Kelas</a>
        <a href="/?redirect=0" class="amto-btn-outline">🏠 Halaman Depan Situs</a>
      </div>
    </div>
    <div class="amto-live-hero-right">
      <div class="amto-stat-box">
        <h4>📊 Ringkasan Operasional AMTO (MVP)</h4>
        <div class="amto-stat-grid">
          <div class="amto-stat-item">
            <strong>148 Peserta</strong>
            <span>Registrasi Trainee</span>
          </div>
          <div class="amto-stat-item">
            <strong>4 Program</strong>
            <span>B1.1, B2, HF, Repo</span>
          </div>
          <div class="amto-stat-item">
            <strong>8 Role RBAC</strong>
            <span>R01 s.d. R08 Aktif</span>
          </div>
          <div class="amto-stat-item">
            <strong>SoD &amp; Audit</strong>
            <span>Terlindungi</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="amto-pillars-grid">
  <div class="amto-pillar-card">
    <div class="amto-pillar-badge">MVP Bulan 1–2</div>
    <h3>📋 Registrasi Peserta</h3>
    <p>Form registrasi, verifikasi dokumen dasar (DP-06), dan manajemen status peserta.</p>
  </div>
  <div class="amto-pillar-card">
    <div class="amto-pillar-badge">MVP Bulan 1–2</div>
    <h3>📅 Penjadwalan Kelas</h3>
    <p>Master program, kuota kelas, alokasi Instruktur (R02) &amp; Assessor (R03).</p>
  </div>
  <div class="amto-pillar-card">
    <div class="amto-pillar-badge">MVP Bulan 1–2</div>
    <h3>📚 LMS Repository</h3>
    <p>Distribusi <em>Training Manual</em> &amp; dokumen terkendali dengan versi &amp; checksum.</p>
  </div>
  <div class="amto-pillar-card">
    <div class="amto-pillar-badge">Bulan 3–5</div>
    <h3>🏅 Logbook &amp; Sertifikasi</h3>
    <p>Verifikasi <em>Practical Logbook</em> berjenjang (SoD) &amp; Register Sertifikat AMTO.</p>
  </div>
</div>
HTML;

$blockconfig = new stdClass();
$blockconfig->title = '';
$blockconfig->format = FORMAT_HTML;
$blockconfig->text = $dashboard_html;
$configdata = base64_encode(serialize($blockconfig));

// Add to both system default dashboard (subpagepattern=2, parentcontextid=1) and admin's dashboard (subpagepattern=4, parentcontextid=5)
$targets = [
    ['parentcontextid' => 1, 'subpagepattern' => '2'],
    ['parentcontextid' => context_user::instance($admin->id)->id, 'subpagepattern' => '4']
];

foreach ($targets as $t) {
    $existing_block = $DB->get_record('block_instances', [
        'blockname' => 'html',
        'parentcontextid' => $t['parentcontextid'],
        'pagetypepattern' => 'my-index',
        'subpagepattern' => $t['subpagepattern']
    ]);
    if ($existing_block) {
        $existing_block->configdata = $configdata;
        $existing_block->defaultregion = 'content';
        $existing_block->defaultweight = -10;
        $existing_block->timemodified = time();
        $DB->update_record('block_instances', $existing_block);
    } else {
        $bi = new stdClass();
        $bi->blockname = 'html';
        $bi->parentcontextid = $t['parentcontextid'];
        $bi->showinsubcontexts = 0;
        $bi->requiredbytheme = 0;
        $bi->pagetypepattern = 'my-index';
        $bi->subpagepattern = $t['subpagepattern'];
        $bi->defaultregion = 'content';
        $bi->defaultweight = -10;
        $bi->configdata = $configdata;
        $bi->timecreated = time();
        $bi->timemodified = time();
        $bi->id = $DB->insert_record('block_instances', $bi);
        context_block::instance($bi->id);

        // Also ensure block_positions puts it at weight -10 if needed
        $DB->delete_records('block_positions', ['blockinstanceid' => $bi->id]);
    }
}

echo "4. Refining Theme Boost SCSS for High-Contrast Navbar & Modern Dashboard Cards...\n";
$customscss = <<<'SCSS'
// AMTO Aviation Portal Custom SCSS for Moodle 5.3 Boost
.navbar.fixed-top {
    background: linear-gradient(90deg, #061326 0%, #0a1f3d 100%) !important;
    border-bottom: 2px solid #0ea5e9 !important;
    box-shadow: 0 4px 16px rgba(6, 19, 38, 0.25);
}
.navbar.fixed-top .navbar-brand {
    color: #ffffff !important;
    font-weight: 800 !important;
    letter-spacing: -0.3px;
}
.navbar.fixed-top .primary-navigation .navigation .nav-link,
.navbar.fixed-top .nav-link,
.navbar.fixed-top .moremenu .nav-link,
.navbar.fixed-top .usermenu .login,
.navbar.fixed-top .usermenu .login a,
.navbar.fixed-top .langmenu .dropdown-toggle,
.navbar.fixed-top .edittoggle .form-check-label,
.navbar.fixed-top .popover-region-toggle i.icon,
.navbar.fixed-top .usermenu .dropdown-toggle {
    color: #e2e8f0 !important;
    font-weight: 600 !important;
}
.navbar.fixed-top .primary-navigation .navigation .nav-link:hover,
.navbar.fixed-top .nav-link:hover {
    color: #38bdf8 !important;
    background: rgba(255, 255, 255, 0.08) !important;
    border-radius: 8px;
}
.navbar.fixed-top .primary-navigation .navigation .nav-link.active,
.navbar.fixed-top .nav-link.active {
    color: #ffffff !important;
    background: #0284c7 !important;
    border-radius: 8px;
    border-bottom: none !important;
}
.navbar.fixed-top .dropdown-menu {
    background: #ffffff !important;
}
.navbar.fixed-top .dropdown-menu .dropdown-item {
    color: #0f172a !important;
}

// Hide redundant "Dashboard" page header title since our Hero Banner is at the top
#page-my-index #page-header,
#page-site-index #page-header {
    display: none;
}

#page.drawers {
    background-color: #f1f5f9 !important;
}

#region-main {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
}

.block_html.card {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    margin-bottom: 8px !important;
}
.block_html.card .card-body {
    padding: 0 !important;
}

.block_myoverview.card,
.block_timeline.card,
.block_calendar_month.card,
.block_recentlyaccesseditems.card {
    border-radius: 16px !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05) !important;
    background: #ffffff !important;
}

.dashboard-card {
    border-radius: 14px !important;
    border: 1px solid #e2e8f0 !important;
    overflow: hidden !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06) !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
}
.dashboard-card:hover {
    transform: translateY(-3px) !important;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12) !important;
}

.amto-live-hero {
    background: linear-gradient(125deg, #061326 0%, #0f2d59 55%, #0284c7 100%);
    color: #ffffff;
    border-radius: 18px;
    padding: 34px 34px;
    margin-bottom: 18px;
    box-shadow: 0 18px 35px -10px rgba(10, 31, 61, 0.25);
}
.amto-live-hero-inner {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    align-items: center;
    justify-content: space-between;
}
.amto-live-hero-left {
    flex: 1 1 460px;
}
.amto-live-hero-right {
    flex: 0 1 320px;
}
.amto-live-pill {
    display: inline-block;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.25);
    padding: 5px 14px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
    color: #38bdf8;
}
.amto-live-hero h1 {
    color: #ffffff !important;
    font-size: 25px;
    font-weight: 800;
    line-height: 1.25;
    margin-bottom: 10px;
}
.amto-live-hero p {
    color: #e2e8f0;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 20px;
}
.amto-live-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.amto-btn-primary, .amto-btn-outline, .amto-btn-gold {
    display: inline-block;
    padding: 9px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none !important;
    transition: all 0.2s ease;
}
.amto-btn-primary {
    background: #0ea5e9;
    color: #ffffff !important;
}
.amto-btn-primary:hover {
    background: #0284c7;
}
.amto-btn-outline {
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.amto-btn-outline:hover {
    background: rgba(255, 255, 255, 0.22);
}
.amto-btn-gold {
    background: #f59e0b;
    color: #061326 !important;
}
.amto-btn-gold:hover {
    background: #fbbf24;
}
.amto-stat-box {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 14px;
    padding: 18px;
}
.amto-stat-box h4 {
    color: #ffffff !important;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 12px;
}
.amto-stat-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.amto-stat-item {
    background: rgba(6, 19, 38, 0.5);
    padding: 10px 12px;
    border-radius: 10px;
}
.amto-stat-item strong {
    display: block;
    color: #38bdf8;
    font-size: 15px;
    font-weight: 800;
}
.amto-stat-item span {
    font-size: 11px;
    color: #cbd5e1;
}
.amto-pillars-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}
.amto-pillar-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
}
.amto-pillar-badge {
    display: inline-block;
    background: #e0f2fe;
    color: #0284c7;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
    margin-bottom: 8px;
}
.amto-pillar-card h3 {
    font-size: 15px;
    font-weight: 700;
    color: #0a1f3d;
    margin-bottom: 6px;
}
.amto-pillar-card p {
    font-size: 12.5px;
    color: #475569;
    margin: 0;
}
SCSS;

set_config('scss', $customscss, 'theme_boost');

echo "5. Purging Moodle Caches...\n";
purge_all_caches();
echo "ALL DONE! Dashboard (/my/) is now fully styled and populated.\n";
