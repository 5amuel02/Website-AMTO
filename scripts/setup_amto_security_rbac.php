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
        'lastname'  => 'Wijaya',
        'role'      => 'r01_trainee',
        'courserole'=> 'student',
    ],
    [
        'username'  => 'instruktur',
        'email'     => 'instruktur@tatc.id',
        'firstname' => 'Ir. Budi',
        'lastname'  => 'Santoso',
        'role'      => 'r02_instructor',
        'courserole'=> 'editingteacher',
    ],
    [
        'username'  => 'assessor',
        'email'     => 'assessor@tatc.id',
        'firstname' => 'Capt. Hendra',
        'lastname'  => 'Wijaya',
        'role'      => 'r03_assessor',
        'courserole'=> 'teacher',
    ],
    [
        'username'      => 'Juhandi',
        'old_usernames' => ['trainingmanager', 'juhandi'],
        'email'         => 'juhandi@gmail.com',
        'old_emails'    => ['tm@tatc.id'],
        'firstname'     => 'Juhandi',
        'lastname'      => '',
        'role'          => 'r04_trainingmanager',
        'courserole'    => 'editingteacher',
        'password'      => 'Juhandi12345',
    ],
    [
        'username'      => 'ImanJM',
        'old_usernames' => ['certofficer', 'imanjm'],
        'email'         => 'imanjanuar44@gmail.com',
        'old_emails'    => ['cert@tatc.id'],
        'password'      => 'Imanjanuar12345',
        'firstname'     => 'Iman',
        'lastname'      => 'Januar M',
        'role'          => 'r05_certofficer',
        'courserole'    => 'teacher',
    ],
    [
        'username'  => 'qa',
        'email'     => 'qa@tatc.id',
        'firstname' => 'Drs. Beng',
        'lastname'  => 'Kusuma',
        'role'      => 'r06_qa',
        'courserole'=> 'teacher',
    ],
    [
        'username'   => 'Umi',
        'email'      => 'umismd@gmail.com',
        'password'   => 'Umi12345',
        'firstname'  => 'Rita',
        'lastname'   => 'Sri Mutiari D',
        'role'       => 'r06_qa',
        'courserole' => 'teacher',
    ],
    [
        'username'   => 'Teguh',
        'email'      => 'asfriyantot@gmail.com',
        'password'   => 'Teguhasfriyanto12345',
        'firstname'  => 'Teguh',
        'lastname'   => 'Asfriyanto',
        'role'       => 'r06_qa',
        'courserole' => 'teacher',
    ],
    [
        'username'   => 'Indra',
        'email'      => 'indset69@gmail.com',
        'password'   => 'Idra12345',
        'firstname'  => 'Indra',
        'lastname'   => 'Setyo Tri Santoso',
        'role'       => 'r06_qa',
        'courserole' => 'teacher',
    ],
    [
        'username'  => 'admin',
        'email'     => 'admin@tatc.id',
        'firstname' => 'Admin',
        'lastname'  => 'User',
        'role'      => 'r07_adminit',
        'courserole'=> 'editingteacher',
    ],
    [
        'username'      => 'anggarusdinar',
        'old_usernames' => ['management', 'manajemen'],
        'email'         => 'anggarusdinar@telkomuniversity.ac.id',
        'old_emails'    => ['manajemen@tatc.id', 'management@tatc.id'],
        'password'      => 'anggarusdinar12345',
        'firstname'     => 'Angga',
        'lastname'      => 'Rusdinar',
        'role'          => 'r08_management',
        'courserole'    => null,
    ],
];

$courses = $DB->get_records_select('course', 'id > 1');
$enrol_manual = enrol_get_plugin('manual');

foreach ($test_accounts as $acc) {
    $acc_pass = $acc['password'] ?? $default_pass;
    $acc_pass_hash = hash_internal_user_password($acc_pass);
    // Moodle mewajibkan kolom mdl_user.username disimpan lowercase; form login otomatis mengubah input 'ImanJM' -> 'imanjm'
    $moodle_uname = strtolower(trim($acc['username']));

    $u = $DB->get_record('user', ['username' => $moodle_uname, 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$u) {
        $u = $DB->get_record('user', ['username' => $acc['username'], 'mnethostid' => $CFG->mnet_localhost_id]);
    }
    if (!$u && !empty($acc['old_usernames'])) {
        foreach ($acc['old_usernames'] as $old_uname) {
            $u = $DB->get_record('user', ['username' => strtolower($old_uname), 'mnethostid' => $CFG->mnet_localhost_id]);
            if ($u) {
                break;
            }
        }
    }
    if (!$u && !empty($acc['old_emails'])) {
        foreach ($acc['old_emails'] as $old_email) {
            $u = $DB->get_record('user', ['email' => $old_email, 'mnethostid' => $CFG->mnet_localhost_id]);
            if ($u) {
                break;
            }
        }
    }

    if (!$u) {
        $u = new stdClass();
        $u->auth         = 'manual';
        $u->confirmed    = 1;
        $u->policyagreed = 1;
        $u->deleted      = 0;
        $u->suspended    = 0;
        $u->mnethostid   = $CFG->mnet_localhost_id;
        $u->username     = $moodle_uname;
        $u->password     = $acc_pass_hash;
        $u->firstname    = $acc['firstname'];
        $u->lastname     = $acc['lastname'];
        $u->email        = $acc['email'];
        $u->city         = 'Bandung';
        $u->country      = 'ID';
        $u->lang         = 'en';
        $u->timecreated  = time();
        $u->timemodified = time();
        $u->id = $DB->insert_record('user', $u);
        echo " [+] Created User: {$acc['username']} (login: {$moodle_uname} / {$acc['email']}) -> ID {$u->id}\n";
    } else {
        $u->auth         = 'manual';
        $u->confirmed    = 1;
        $u->deleted      = 0;
        $u->suspended    = 0;
        $u->username     = $moodle_uname;
        $u->password     = $acc_pass_hash;
        $u->email        = $acc['email'];
        $u->firstname    = $acc['firstname'];
        $u->lastname     = $acc['lastname'];
        $u->timemodified = time();
        $DB->update_record('user', $u);
        echo " [=] Updated User & Reset Password: {$acc['username']} (login: {$moodle_uname} / {$acc['email']}) -> ID {$u->id}\n";
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
    $auth_ok = authenticate_user_login($acc['username'], $acc_pass, false, $errorcode);
    echo "     -> Auth Check ({$acc['username']} / {$acc_pass}): " . ($auth_ok ? "OK (UID {$auth_ok->id})" : "FAILED (code {$errorcode})") . "\n";

    // Also ensure the account exists in tatc_web.users (Portal React) with matching bcryptjs ($2a$10$) password hash
    try {
        $tatc_bcrypt_hash = str_replace('$2y$', '$2a$', password_hash($acc_pass, PASSWORD_BCRYPT));
        $tatc_role = 'ADMIN';
        if ($acc['role'] === 'r01_trainee') {
            $tatc_role = 'PESERTA';
        } else if (in_array($acc['role'], ['r02_instructor', 'r03_assessor'], true)) {
            $tatc_role = 'INSTRUKTUR';
        } else if ($acc['role'] === 'r07_adminit') {
            $tatc_role = 'SUPERADMIN';
        }
        $full_name = trim($acc['firstname'] . ' ' . $acc['lastname']);
        if (!empty($acc['old_emails'])) {
            foreach ($acc['old_emails'] as $old_email) {
                try {
                    $DB->execute(
                        "UPDATE IGNORE tatc_web.users SET email = ? WHERE email = ?",
                        [$acc['email'], $old_email]
                    );
                } catch (Throwable $e) {
                    // Ignore if old email not present
                }
            }
        }
        $DB->execute(
            "INSERT INTO tatc_web.users (email, password_hash, role, status, email_verified_at, created_at, updated_at)
             VALUES (?, ?, ?, 'ACTIVE', NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), status = 'ACTIVE', email_verified_at = NOW()",
            [$acc['email'], $tatc_bcrypt_hash, $tatc_role]
        );
    } catch (Throwable $e) {
        // Ignore if tatc_web is not present
    }
}

// Also ensure the 2 new Portal TATC test participant accounts (dimas@tatc.id & reza@tatc.id) exist in tatc_web for registration flow testing
try {
    $tatc_bcrypt_hash = str_replace('$2y$', '$2a$', password_hash($default_pass, PASSWORD_BCRYPT));
    $portal_only_participants = [
        ['email' => 'dimas@tatc.id', 'p_number' => 'P-2026-NEW01', 'full_name' => 'Dimas Saputra',   'nik' => '3273011508990002', 'phone' => '081288997766', 'institution' => 'Telkom University'],
        ['email' => 'reza@tatc.id',  'p_number' => 'P-2026-NEW02', 'full_name' => 'Reza Firmansyah', 'nik' => '3273012204000003', 'phone' => '081377889900', 'institution' => 'GMF AeroAsia / Calon Peserta'],
    ];
    foreach ($portal_only_participants as $pop) {
        $DB->execute(
            "INSERT INTO tatc_web.users (email, password_hash, role, status, email_verified_at, created_at, updated_at)
             VALUES (?, ?, 'PESERTA', 'ACTIVE', NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'PESERTA', status = 'ACTIVE', email_verified_at = NOW()",
            [$pop['email'], $tatc_bcrypt_hash]
        );
        $DB->execute(
            "INSERT INTO tatc_web.participants (participant_number, full_name, nik, phone, email, institution, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', NOW(), NOW())
             ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), nik = VALUES(nik), phone = VALUES(phone), institution = VALUES(institution), status = 'ACTIVE'",
            [$pop['p_number'], $pop['full_name'], $pop['nik'], $pop['phone'], $pop['email'], $pop['institution']]
        );
    }
} catch (Throwable $e) {
    // Ignore if tatc_web is not present
}

echo "=== 8 Role RBAC & Official Test Accounts Provisioning Completed ===\n";
