<?php
// Merge Dashboard & Beranda into a single Dashboard item across Admin, Instructor, and Student menus,
// and fix Moodle 5.3 React PrimaryNav text color (--mds-text-default / .primarynav-navbar)

// 1. Update output/primary.php to remove redundant "Beranda|/?redirect=0\n" from Student and Instructor menus
$outprimarypath = 'C:/moodle/server/moodle/public/lib/classes/navigation/output/primary.php';
$op = file_get_contents($outprimarypath);
$op = str_replace('"Beranda|/?redirect=0\n" . ', '', $op);
$op = str_replace("\"Beranda|/?redirect=0\\n\"\r\n                . ", '', $op);
$op = str_replace("\"Beranda|/?redirect=0\\n\"\n                . ", '', $op);
file_put_contents($outprimarypath, $op);

// 2. Update views/primary.php so 'home' is not added when 'myhome' (Dashboard) is shown
$viewsprimarypath = 'C:/moodle/server/moodle/public/lib/classes/navigation/views/primary.php';
$vp = file_get_contents($viewsprimarypath);
$vp = str_replace(
    'if ($showhomenode && !empty($CFG->enablemyhome)) {',
    'if (false && $showhomenode && !empty($CFG->enablemyhome)) {',
    $vp
);
file_put_contents($viewsprimarypath, $vp);

define('CLI_SCRIPT', true);
require('C:/moodle/server/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

// 3. Update Admin custommenuitems without "Beranda"
$custommenu = <<<'MENU'
Registrasi & Peserta|/user/index.php
-Daftar Peserta (Trainee)|/user/index.php
-Registrasi Peserta Baru|/user/editadvanced.php?id=-1
-Profil Pribadi|/user/profile.php
Program & Jadwal|/course/index.php
-Program & Kurikulum AMTO|/course/index.php
-Kelas & Jadwal Pelatihan|/calendar/view.php?view=month
-Manajemen Kelas & Kategori|/course/management.php
LMS Repository|/course/index.php
-Katalog Materi LMS|/course/index.php
-Dokumen Terkendali (QMS)|/files/index.php
Evaluasi & Logbook|/grade/report/overview/index.php
-Ujian & Bank Soal|/question/edit.php?courseid=1
-Practical Task & Logbook|/grade/report/overview/index.php
-Rekaman Kompetensi|/admin/tool/lp/competencyframeworks.php?pagecontextid=1
Sertifikasi & QA|/report/log/index.php
-Register Sertifikat AMTO|/badges/index.php?type=1
-Audit & Corrective Action (QA)|/report/outline/index.php?id=1
-Audit Trail & Log Sistem|/report/log/index.php
UI/UX Prototype|/amto/
MENU;

set_config('custommenuitems', $custommenu);
set_config('defaulthomepage', 1); // HOMEPAGE_MY (Dashboard)

// 4. Update SCSS so Moodle 5.3 React PrimaryNav (.primarynav-navbar) items are bright white
$scss = get_config('theme_boost', 'scss');
$react_nav_fix = <<<'SCSS'

// Moodle 5.3 React PrimaryNav high-contrast white text fix
.navbar.fixed-top {
    --mds-text-default: #f8fafc !important;
    --mds-text-subtle: #e2e8f0 !important;
}
.navbar.fixed-top .primarynav-navbar .nav-link,
.navbar.fixed-top .primarynav-navbar a.nav-link,
.navbar.fixed-top .primarynav-navbar button.nav-link,
.navbar.fixed-top .primarynav-navbar .more-nav > li > a,
.navbar.fixed-top .primarynav-navbar .more-nav > li > button {
    color: #f8fafc !important;
    font-weight: 600 !important;
}
.navbar.fixed-top .primarynav-navbar .nav-link[aria-current="page"],
.navbar.fixed-top .primarynav-navbar .nav-link.active {
    background: #0284c7 !important;
    color: #ffffff !important;
    border-radius: 8px !important;
}
SCSS;

if (strpos($scss, 'primarynav-navbar') === false) {
    set_config('scss', $scss . "\n" . $react_nav_fix, 'theme_boost');
}

purge_all_caches();
echo "Merged Dashboard & Beranda and updated PrimaryNav styling!\n";
