<?php
define('CLI_SCRIPT', true);
require('C:/moodle/server/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

$custommenu = <<<'MENU'
Beranda|/?redirect=0
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

$login_scss = <<<'SCSS'
// Clean AMTO Aviation Theme SCSS for Moodle 5.3 Boost
.navbar.fixed-top {
    background: linear-gradient(90deg, #061326 0%, #0a1f3d 100%) !important;
    border-bottom: 2px solid #0ea5e9 !important;
    box-shadow: 0 4px 16px rgba(6, 19, 38, 0.22);
}
.navbar.fixed-top .navbar-brand {
    color: #ffffff !important;
    font-weight: 800 !important;
    letter-spacing: -0.3px;
}
.navbar.fixed-top .moremenu .nav-tabs {
    border-bottom: none !important;
    gap: 4px;
}
.navbar.fixed-top .moremenu .nav-tabs .nav-link,
.navbar.fixed-top .primary-navigation .navigation .nav-link,
.navbar.fixed-top .nav-link,
.navbar.fixed-top #usernavigation .nav-link,
.navbar.fixed-top #usernavigation .form-check-label,
.navbar.fixed-top #usernavigation .usermenu .login,
.navbar.fixed-top #usernavigation .usermenu .login a,
.navbar.fixed-top #usernavigation .dropdown-toggle,
.navbar.fixed-top #usernavigation i.icon {
    color: #f1f5f9 !important;
    font-weight: 600 !important;
    background: transparent !important;
    border: none !important;
    border-radius: 8px !important;
    padding: 7px 12px !important;
    font-size: 13.5px !important;
    transition: all 0.15s ease;
}
.navbar.fixed-top .moremenu .nav-tabs .nav-link:hover,
.navbar.fixed-top .primary-navigation .navigation .nav-link:hover {
    color: #ffffff !important;
    background: rgba(14, 165, 233, 0.2) !important;
}
.navbar.fixed-top .moremenu .nav-tabs .nav-link.active,
.navbar.fixed-top .primary-navigation .navigation .nav-link.active {
    color: #ffffff !important;
    background: #0284c7 !important;
    font-weight: 700 !important;
    box-shadow: 0 2px 8px rgba(2, 132, 199, 0.4);
}
.navbar.fixed-top .moremenu .nav-tabs .nav-link.active::before {
    display: none !important;
}
.navbar.fixed-top .dropdown-menu {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.12) !important;
    padding: 6px !important;
}
.navbar.fixed-top .dropdown-menu .dropdown-item {
    color: #0f172a !important;
    font-weight: 500 !important;
    font-size: 13.5px !important;
    border-radius: 6px !important;
    padding: 8px 12px !important;
}
.navbar.fixed-top .dropdown-menu .dropdown-item:hover,
.navbar.fixed-top .dropdown-menu .dropdown-item:focus {
    background: #e0f2fe !important;
    color: #0284c7 !important;
}
#page.drawers {
    background-color: #f8fafc !important;
}
.card, .coursebox {
    border-radius: 14px !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04) !important;
}

// ============================================================
// AMTO SPLIT-SCREEN LOGIN PAGE (CeLOE Layout adapted for AMTO)
// ============================================================
.pagelayout-login #page {
    background: #ffffff !important;
    padding: 0 !important;
    margin: 0 !important;
}
.pagelayout-login #page-footer {
    display: none !important;
}
.amto-login-split {
    min-height: 100vh;
    width: 100%;
    margin: 0 !important;
}
.amto-login-left {
    background: #0d274c !important;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 64px 80px !important;
    color: #ffffff;
}
.amto-left-circle-top {
    position: absolute;
    width: 520px;
    height: 520px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.045);
    top: -130px;
    left: -130px;
    pointer-events: none;
}
.amto-left-circle-bottom {
    position: absolute;
    width: 480px;
    height: 480px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.04);
    bottom: -140px;
    right: -90px;
    pointer-events: none;
}
.amto-left-inner {
    position: relative;
    z-index: 2;
    max-width: 520px;
    width: 100%;
}
.amto-brand-row {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-bottom: 32px;
}
.amto-brand-badge {
    background: #ffffff;
    border-radius: 10px;
    padding: 10px 20px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
}
.amto-brand-code {
    font-size: 26px;
    font-weight: 900;
    color: #0d274c;
    letter-spacing: 1px;
}
.amto-brand-lms {
    font-size: 26px;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.15;
}
.amto-brand-sub {
    font-size: 16px;
    color: rgba(255, 255, 255, 0.88);
    font-weight: 400;
}
.amto-lang-pill {
    display: inline-flex;
    background: rgba(255, 255, 255, 0.14);
    padding: 5px;
    border-radius: 999px;
    margin-bottom: 28px;
}
.amto-lang-btn {
    border: none;
    background: transparent;
    color: rgba(255, 255, 255, 0.85);
    padding: 6px 18px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.amto-lang-btn.active {
    background: rgba(255, 255, 255, 0.24);
    color: #ffffff;
    font-weight: 700;
}
.amto-welcome-title {
    color: #ffffff !important;
    font-size: 38px;
    font-weight: 800;
    margin-bottom: 20px;
    letter-spacing: -0.5px;
}
.amto-welcome-list {
    padding-left: 20px;
    margin: 0;
    color: rgba(255, 255, 255, 0.94);
    font-size: 15px;
    line-height: 1.65;
}
.amto-welcome-list li {
    margin-bottom: 12px;
}

// RIGHT PANEL
.amto-login-right {
    background: #ffffff !important;
    min-height: 100vh;
    padding: 32px 48px 20px !important;
}
.amto-login-topbar {
    width: 100%;
}
.amto-back-link {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: #64748b !important;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none !important;
    transition: color 0.15s ease;
}
.amto-back-link:hover {
    color: #0d274c !important;
}
.amto-login-center {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
}
.amto-login-box {
    width: 100%;
    max-width: 380px;
}
.amto-form-title {
    font-size: 28px;
    font-weight: 800;
    color: #1e293b !important;
    margin-bottom: 6px;
}
.amto-form-subtitle {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 24px;
}
.amto-sso-btn {
    width: 100%;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 13px 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    transition: all 0.2s ease;
}
.amto-sso-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.amto-divider {
    display: flex;
    align-items: center;
    text-align: center;
    margin: 18px 0;
    color: #94a3b8;
    font-size: 12px;
}
.amto-divider::before,
.amto-divider::after {
    content: '';
    flex: 1;
    border-bottom: 1px solid #e2e8f0;
}
.amto-divider span {
    padding: 0 10px;
}
.amto-input {
    border-radius: 8px !important;
    border: 1px solid #cbd5e1 !important;
    padding: 11px 14px !important;
    font-size: 14px !important;
}
.amto-input:focus {
    border-color: #0d274c !important;
    box-shadow: 0 0 0 3px rgba(13, 39, 76, 0.12) !important;
}
.amto-forgot-link {
    font-size: 12.5px;
    color: #0d274c !important;
    text-decoration: none;
    font-weight: 600;
}
.amto-submit-btn {
    background: #0d274c !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 11px 18px !important;
    border-radius: 8px !important;
    border: none !important;
    font-size: 14px !important;
}
.amto-submit-btn:hover {
    background: #163e75 !important;
}
.amto-meta-row {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 18px;
    margin-top: 22px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.amto-lang-dropdown .dropdown-toggle,
.amto-cookie-link {
    color: #0d274c !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
}
.amto-no-account {
    text-align: center;
    color: #64748b;
    font-size: 13px;
    line-height: 1.5;
}
.amto-login-footer {
    border-top: 1px solid #f1f5f9;
    padding-top: 16px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 12px;
    font-size: 12px;
    color: #64748b;
}
.amto-login-footer-links {
    display: flex;
    gap: 16px;
}
.amto-login-footer-links a {
    color: #475569 !important;
    text-decoration: none;
}
SCSS;

set_config('scss', $login_scss, 'theme_boost');
purge_all_caches();
echo "Navbar menu items and high-contrast SCSS updated!\n";
