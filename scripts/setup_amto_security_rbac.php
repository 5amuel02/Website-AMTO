<?php
/**
 * [SEC-FIX-04] AMTO 8-Role RBAC & Security Provisioning Script (R01-R08).
 * Registers the 8 official AMTO roles in Moodle database per Form Kesepakatan Security & RBAC Website AMTO.
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/accesslib.php');

global $DB;

echo "=== Provisioning 8 AMTO RBAC Roles (R01 s/d R08) ===\n";

$amto_roles = [
    [
        'shortname'   => 'r01_trainee',
        'name'        => 'R01 - Peserta / Trainee AMTO',
        'description' => 'Mengikuti pelatihan; melihat profil, materi, jadwal, presensi, ujian, logbook, dan hasil miliknya sendiri (SCP-01).',
        'archetype'   => 'student',
    ],
    [
        'shortname'   => 'r02_instructor',
        'name'        => 'R02 - Instruktur AMTO',
        'description' => 'Mengelola aktivitas kelas yang ditugaskan; presensi, materi, penilaian, dan verifikasi logbook (SCP-02).',
        'archetype'   => 'editingteacher',
    ],
    [
        'shortname'   => 'r03_assessor',
        'name'        => 'R03 - Examiner / Assessor AMTO',
        'description' => 'Melaksanakan ujian/assessment berdasarkan otorisasi, rubrik, dan jadwal; tunduk pada aturan SoD (SCP-03).',
        'archetype'   => 'teacher',
    ],
    [
        'shortname'   => 'r04_trainingmanager',
        'name'        => 'R04 - Training Manager AMTO',
        'description' => 'Mengelola program, kurikulum, kelas, jadwal, alokasi instruktur, dan verifikasi pendaftaran peserta (SCP-04).',
        'archetype'   => 'coursecreator',
    ],
    [
        'shortname'   => 'r05_certofficer',
        'name'        => 'R05 - Certification Officer AMTO',
        'description' => 'Memeriksa checklist, dokumen prasyarat, bukti kompetensi, rekomendasi, dan register sertifikat (SCP-05).',
        'archetype'   => 'teacher',
    ],
    [
        'shortname'   => 'r06_qa',
        'name'        => 'R06 - Quality Assurance (QA) AMTO',
        'description' => 'Mengelola dokumen terkendali, audit internal, temuan, corrective action, dan akses Read-Only Audit (SCP-06).',
        'archetype'   => 'teacher',
    ],
    [
        'shortname'   => 'r07_adminit',
        'name'        => 'R07 - Administrator IT AMTO',
        'description' => 'Mengelola akun, role, konfigurasi, integrasi, dan log teknis; dilarang mengubah nilai/sertifikat (SCP-08 / ARBAC97).',
        'archetype'   => 'manager',
    ],
    [
        'shortname'   => 'r08_management',
        'name'        => 'R08 - Manajemen AMTO',
        'description' => 'Melihat dashboard agregat, KPI, laporan, risiko, dan memberikan persetujuan sesuai delegasi (SCP-07).',
        'archetype'   => 'user',
    ],
];

foreach ($amto_roles as $r) {
    $existing = $DB->get_record('role', ['shortname' => $r['shortname']]);
    if (!$existing) {
        $roleid = create_role($r['name'], $r['shortname'], $r['description'], $r['archetype']);
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM, CONTEXT_COURSECAT, CONTEXT_COURSE, CONTEXT_MODULE]);
        echo " [+] Created Role: {$r['name']} ({$r['shortname']}) -> ID {$roleid}\n";
    } else {
        echo " [=] Role Exists: {$r['name']} ({$r['shortname']}) -> ID {$existing->id}\n";
    }
}

echo "\n=== Applying IAM, Session, Lockout & SSRF Hardening Policies (IAM-01..IAM-11, APP-01..APP-08) ===\n";

// IAM-03 Password Policy
set_config('passwordpolicy', 1);
set_config('minpasswordlength', 8);
set_config('minpassworddigits', 1);
set_config('minpasswordlower', 1);
set_config('minpasswordupper', 1);
set_config('minpasswordnonalphanum', 1);

// IAM-05 Password Reset Token Expiration (30 minutes)
set_config('pwresettime', 1800);

// IAM-06 Brute-Force Account Lockout (5 attempts within 15 mins -> 30 mins lockout)
set_config('lockoutthreshold', 5);
set_config('lockoutwindow', 900);
set_config('lockoutduration', 1800);

// IAM-07 Session Idle Timeout (30 minutes) & Cookie Hardening
set_config('sessiontimeout', 1800);
set_config('cookiehttponly', 1);

// SCP-01 Profile & Privacy Hardening
set_config('forceloginforprofiles', 1);
set_config('profilesforenrolledusersonly', 1);
set_config('cronclionly', 1);

// APP-01 SSRF Prevention via Moodle cURL blocked hosts list
$blocked_hosts = "127.0.0.0/8\n169.254.169.254\nlocalhost\n0.0.0.0/8\n::1";
set_config('curlsecurityblockedhosts', $blocked_hosts);

echo " [+] Applied IAM-03 Password Policy, IAM-05 Reset Expiry, IAM-06 Lockout, IAM-07 Session Timeout, and APP-01 SSRF Protection.\n";
echo "=== 8 Role RBAC & Security Hardening Provisioning Completed ===\n";
