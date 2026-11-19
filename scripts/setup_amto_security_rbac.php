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

echo "=== 8 Role RBAC Provisioning Completed ===\n";
