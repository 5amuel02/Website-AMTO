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

// IAM-01 Allow login via either Username LMS OR Email Portal (@tatc.id)
set_config('authloginviaemail', 1);

echo " [+] Applied IAM-03 Password Policy, IAM-05 Reset Expiry, IAM-06 Lockout, IAM-07 Session Timeout, and APP-01 SSRF Protection.\n";

echo "\n=== Provisioning Official AMTO RBAC Test Accounts (R01 s/d R08) with Password: Admin123@2 ===\n";
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/enrollib.php');

$sysctx = context_system::instance();
$default_pass = 'Admin123@2';
$pass_hash = hash_internal_user_password($default_pass);

$test_accounts = [
    [
        'username'  => 'peserta',
        'email'     => 'peserta@tatc.id',
        'firstname' => 'Andi Pratama',
        'lastname'  => '(R01 Peserta / Trainee)',
        'role'      => 'r01_trainee',
        'courserole'=> 'student',
    ],
    [
        'username'  => 'instruktur',
        'email'     => 'instruktur@tatc.id',
        'firstname' => 'Ir. Budi Santoso',
        'lastname'  => '(R02 Instruktur AMTO)',
        'role'      => 'r02_instructor',
        'courserole'=> 'editingteacher',
    ],
    [
        'username'  => 'assessor',
        'email'     => 'assessor@tatc.id',
        'firstname' => 'Capt. Hendra Wijaya',
        'lastname'  => '(R03 Examiner / Assessor)',
        'role'      => 'r03_assessor',
        'courserole'=> 'teacher',
    ],
    [
        'username'  => 'trainingmanager',
        'email'     => 'tm@tatc.id',
        'firstname' => 'Juhandi, S.T., M.T.',
        'lastname'  => '(R04 Training Manager)',
        'role'      => 'r04_trainingmanager',
        'courserole'=> 'editingteacher',
    ],
    [
        'username'  => 'certofficer',
        'email'     => 'cert@tatc.id',
        'firstname' => 'Iman Setiawan',
        'lastname'  => '(R05 Certification Officer)',
        'role'      => 'r05_certofficer',
        'courserole'=> 'teacher',
    ],
    [
        'username'  => 'qa',
        'email'     => 'qa@tatc.id',
        'firstname' => 'Drs. Beng Kusuma',
        'lastname'  => '(R06 Quality Assurance / QA)',
        'role'      => 'r06_qa',
        'courserole'=> 'teacher',
    ],
    [
        'username'  => 'admin',
        'email'     => 'admin@tatc.id',
        'firstname' => 'Administrator IT',
        'lastname'  => '(R07 Super Admin AMTO)',
        'role'      => 'r07_adminit',
        'courserole'=> 'editingteacher',
    ],
    [
        'username'  => 'management',
        'email'     => 'manajemen@tatc.id',
        'firstname' => 'Ir. Angga Dirgantara',
        'lastname'  => '(R08 Manajemen Eksekutif AMTO)',
        'role'      => 'r08_management',
        'courserole'=> null,
    ],
];

$courses = $DB->get_records_select('course', 'id > 1');
$enrol_manual = enrol_get_plugin('manual');

foreach ($test_accounts as $acc) {
    $u = $DB->get_record('user', ['username' => $acc['username'], 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$u) {
        $u = new stdClass();
        $u->auth         = 'manual';
        $u->confirmed    = 1;
        $u->policyagreed = 1;
        $u->deleted      = 0;
        $u->suspended    = 0;
        $u->mnethostid   = $CFG->mnet_localhost_id;
        $u->username     = $acc['username'];
        $u->password     = $pass_hash;
        $u->firstname    = $acc['firstname'];
        $u->lastname     = $acc['lastname'];
        $u->email        = $acc['email'];
        $u->city         = 'Bandung';
        $u->country      = 'ID';
        $u->lang         = 'en';
        $u->timecreated  = time();
        $u->timemodified = time();
        $u->id = $DB->insert_record('user', $u);
        echo " [+] Created User: {$acc['username']} ({$acc['email']}) -> ID {$u->id}\n";
    } else {
        $u->auth         = 'manual';
        $u->confirmed    = 1;
        $u->deleted      = 0;
        $u->suspended    = 0;
        $u->password     = $pass_hash;
        $u->email        = $acc['email'];
        $u->firstname    = $acc['firstname'];
        $u->lastname     = $acc['lastname'];
        $u->timemodified = time();
        $DB->update_record('user', $u);
        echo " [=] Updated User & Reset Password: {$acc['username']} ({$acc['email']}) -> ID {$u->id}\n";
    }

    // Clear any lockout or forcepasswordchange flags so login works immediately
    unset_user_preference('login_lockout', $u->id);
    unset_user_preference('login_failed_count', $u->id);
    unset_user_preference('auth_forcepasswordchange', $u->id);

    // Assign system RBAC role
    $role_rec = $DB->get_record('role', ['shortname' => $acc['role']]);
    if ($role_rec) {
        role_assign($role_rec->id, $u->id, $sysctx->id);
    }

    // Also assign standard Moodle archetype role at system/course level so dashboard & courses populate
    if (!empty($acc['courserole'])) {
        $crole = $DB->get_record('role', ['shortname' => $acc['courserole']]);
        if ($crole && $enrol_manual) {
            foreach ($courses as $course) {
                $instances = enrol_get_instances($course->id, true);
                $manualinstance = null;
                foreach ($instances as $inst) {
                    if ($inst->enrol === 'manual') {
                        $manualinstance = $inst;
                        break;
                    }
                }
                if ($manualinstance) {
                    $enrol_manual->enrol_user($manualinstance, $u->id, $crole->id, time());
                }
            }
        }
    }

    // Verify authenticate_user_login works
    $reason = null;
    $errorcode = null;
    $auth_ok = authenticate_user_login($acc['username'], $default_pass, false, $errorcode);
    echo "     -> Auth Check ({$acc['username']} / {$default_pass}): " . ($auth_ok ? "OK (UID {$auth_ok->id})" : "FAILED (code {$errorcode})") . "\n";
}

echo "=== 8 Role RBAC & Official Test Accounts Provisioning Completed ===\n";
