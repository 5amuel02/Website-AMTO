<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Ensure AMTO custom tables exist for Registrasi Peserta & Jadwal Pelatihan.
 */
function amto_ensure_tables(): void {
    global $DB;

    $dbman = $DB->get_manager();

    // 1. Table: amto_schedules
    if (!$dbman->table_exists('amto_schedules')) {
        $sql = "CREATE TABLE {amto_schedules} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            batch_code VARCHAR(50) NOT NULL,
            program_name VARCHAR(255) NOT NULL,
            category_type VARCHAR(100) NOT NULL,
            training_mode VARCHAR(100) NOT NULL,
            room_location VARCHAR(150) NOT NULL,
            instructor_name VARCHAR(150) NOT NULL,
            start_date VARCHAR(30) NOT NULL,
            end_date VARCHAR(30) NOT NULL,
            schedule_time VARCHAR(100) NOT NULL,
            quota INT(10) NOT NULL DEFAULT 24,
            status VARCHAR(40) NOT NULL DEFAULT 'Open Registration',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }

    // 2. Table: amto_registrations
    if (!$dbman->table_exists('amto_registrations')) {
        $sql = "CREATE TABLE {amto_registrations} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            registration_no VARCHAR(50) NOT NULL,
            userid BIGINT(10) NOT NULL DEFAULT 0,
            username VARCHAR(100) NOT NULL,
            fullname VARCHAR(200) NOT NULL,
            nik VARCHAR(50) NOT NULL,
            email VARCHAR(150) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            organization VARCHAR(200) NOT NULL,
            amel_no VARCHAR(100) DEFAULT '',
            schedule_id BIGINT(10) NOT NULL DEFAULT 0,
            program_name VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL DEFAULT '',
            doc_ktp VARCHAR(255) DEFAULT '',
            doc_ijazah VARCHAR(255) DEFAULT '',
            doc_pendukung VARCHAR(255) DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            admin_notes TEXT DEFAULT NULL,
            verified_by BIGINT(10) NOT NULL DEFAULT 0,
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            timemodified BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }

    // 3. Table: amto_repository (LMS Repository & Training Manuals per CASR Part 147)
    if (!$dbman->table_exists('amto_repository')) {
        $sql = "CREATE TABLE {amto_repository} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            module_code VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            category_type VARCHAR(100) NOT NULL,
            batch_code VARCHAR(80) NOT NULL DEFAULT 'Semua Batch',
            file_type VARCHAR(60) NOT NULL DEFAULT 'PDF Training Manual',
            file_path VARCHAR(255) NOT NULL DEFAULT '',
            file_size VARCHAR(40) NOT NULL DEFAULT '',
            doc_version VARCHAR(50) NOT NULL DEFAULT 'Rev. 01 - 2026',
            description TEXT DEFAULT NULL,
            uploaded_by BIGINT(10) NOT NULL DEFAULT 0,
            uploader_name VARCHAR(150) NOT NULL DEFAULT 'Admin TATC',
            status VARCHAR(30) NOT NULL DEFAULT 'Published',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }
}

/**
 * Parse and verify the tatc_session cookie set by tatcwebreact on localhost.
 */
function amto_parse_tatc_session_cookie(): ?array {
    if (empty($_COOKIE['tatc_session']) || strpos($_COOKIE['tatc_session'], '.') === false) {
        return null;
    }
    $parts = explode('.', $_COOKIE['tatc_session'], 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$payload_b64, $sig] = $parts;
    $secret = 'tatc-amto-integrated-portal-lms-secret-key-2026-secure';
    $expected_sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload_b64, $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected_sig, $sig)) {
        return null;
    }
    $json = base64_decode(strtr($payload_b64, '-_', '+/'), true);
    if (!$json) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['email'])) {
        return null;
    }
    if (!empty($data['exp']) && $data['exp'] < time()) {
        return null;
    }
    return $data;
}

/**
 * Find or auto-create a Moodle user matching a tatcwebreact user (email, role, name) and log them in.
 */
function amto_login_moodle_user_from_tatc(string $email, string $tatc_role = 'PESERTA', string $name = ''): ?object {
    global $DB, $CFG, $USER;
    require_once($CFG->dirroot . '/user/lib.php');

    $email = strtolower(trim($email));
    $tatc_role = strtoupper(trim($tatc_role));
    if ($email === '') {
        return null;
    }

    $syscontext = context_system::instance();

    // Map tatcwebreact emails/roles to Moodle accounts
    $target_user = null;
    if ($email === 'admin@tatc.id' || $tatc_role === 'SUPERADMIN') {
        $target_user = $DB->get_record('user', ['username' => 'admin', 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
    }

    if (!$target_user) {
        // Look up non-admin user by email first
        $candidates = $DB->get_records_select(
            'user',
            'LOWER(email) = :e AND deleted = 0 AND mnethostid = :m AND username <> \'admin\'',
            ['e' => $email, 'm' => $CFG->mnet_localhost_id],
            'id ASC'
        );
        if (!empty($candidates)) {
            $target_user = reset($candidates);
        }
    }

    if (!$target_user) {
        // Auto-provision a new Moodle account for this tatcwebreact user
        $base_uname = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0]));
        if ($base_uname === '' || $base_uname === 'admin' || $base_uname === 'guest') {
            $base_uname = 'tatcuser';
        }
        $uname = $base_uname;
        $idx = 1;
        while ($DB->record_exists('user', ['username' => $uname, 'mnethostid' => $CFG->mnet_localhost_id])) {
            $uname = $base_uname . $idx;
            $idx++;
        }

        $display = trim($name) !== '' ? trim($name) : ucfirst($base_uname);
        $parts = preg_split('/\s+/', $display, 2);
        $firstname = $parts[0] ?? $display;
        $lastname = (!empty($parts[1])) ? $parts[1] : 'TATC';

        $nu = new stdClass();
        $nu->auth = 'manual';
        $nu->confirmed = 1;
        $nu->policyagreed = 1;
        $nu->deleted = 0;
        $nu->suspended = 0;
        $nu->mnethostid = $CFG->mnet_localhost_id;
        $nu->username = $uname;
        $nu->password = hash_internal_user_password('Admin123@2');
        $nu->firstname = $firstname;
        $nu->lastname = $lastname;
        $nu->email = $email;
        $nu->city = 'Bandung';
        $nu->country = 'ID';
        $nu->lang = 'en';
        $nu->timecreated = time();
        $nu->timemodified = time();
        $uid = $DB->insert_record('user', $nu);
        $target_user = $DB->get_record('user', ['id' => $uid]);
    }

    if ($target_user && $target_user->username !== 'admin') {
        $moodle_role_short = 'student';
        if ($tatc_role === 'INSTRUKTUR' || $tatc_role === 'INSTRUCTOR') {
            $moodle_role_short = 'editingteacher';
        } else if ($tatc_role === 'ADMIN') {
            $moodle_role_short = 'manager';
        }
        $role_rec = $DB->get_record('role', ['shortname' => $moodle_role_short]);
        if ($role_rec) {
            role_assign($role_rec->id, $target_user->id, $syscontext->id);
        }
    }

    if ($target_user && (!isloggedin() || isguestuser() || (int)$USER->id !== (int)$target_user->id)) {
        complete_user_login($target_user);
        if (!empty($USER->access['rsw'])) {
            $USER->access['rsw'] = [];
        }
        return $target_user;
    }

    return $target_user;
}

/**
 * Automatically sync Moodle login session if the user logged in as a different account on tatcwebreact.
 */
function amto_auto_sync_from_tatc_cookie(): void {
    global $USER, $FULLME;
    $cookie_data = amto_parse_tatc_session_cookie();
    if (!$cookie_data || empty($cookie_data['email'])) {
        return;
    }
    $token_raw = $_COOKIE['tatc_session'] ?? '';
    $last_synced = $_SESSION['amto_last_tatc_token'] ?? '';

    $tatc_email = strtolower(trim($cookie_data['email']));
    $tatc_role = strtoupper(trim($cookie_data['role'] ?? 'PESERTA'));

    // Check if current Moodle user differs from the active tatcwebreact cookie user
    $need_switch = false;
    if ($last_synced !== $token_raw) {
        $need_switch = true;
    } else if (!isloggedin() || isguestuser()) {
        $need_switch = true;
    } else {
        if ($tatc_email === 'admin@tatc.id' && $USER->username !== 'admin') {
            $need_switch = true;
        } else if ($tatc_email !== 'admin@tatc.id' && (strtolower($USER->email ?? '') !== $tatc_email || $USER->username === 'admin')) {
            $need_switch = true;
        }
    }

    if ($need_switch) {
        $_SESSION['amto_last_tatc_token'] = $token_raw;
        $prev_id = (int)($USER->id ?? 0);
        $switched_to = amto_login_moodle_user_from_tatc($tatc_email, $tatc_role);
        if ($switched_to && (int)$switched_to->id !== $prev_id && !defined('CLI_SCRIPT')) {
            $redirect_to = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/my/';
            header('Location: ' . $redirect_to);
            exit;
        }
    }
}

/**
 * Determine current effective role ('admin', 'instructor', 'student', or 'guest').
 */
function amto_current_role(): string {
    amto_auto_sync_from_tatc_cookie();
    if (!isloggedin() || isguestuser()) {
        return 'guest';
    }
    if (function_exists('amto_get_effective_role')) {
        return amto_get_effective_role();
    }
    return is_siteadmin() ? 'admin' : 'student';
}

/**
 * Shared CSS for AMTO Registrasi & Jadwal pages matching tatcwebreact.
 */
function amto_render_shared_styles(): string {
    return <<<'HTML'
<style>
.tatc-wrap {
    max-width: 1220px;
    margin: 0 auto;
    padding: 8px 4px 48px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #0f172a;
}
.tatc-hero {
    background: linear-gradient(135deg, #991b1b 0%, #b91c1c 55%, #dc2626 100%);
    border-radius: 16px;
    padding: 28px 32px;
    color: #ffffff;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    box-shadow: 0 12px 30px rgba(185, 28, 28, 0.18);
    margin-bottom: 24px;
}
.tatc-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.16);
    border: 1px solid rgba(255, 255, 255, 0.25);
    padding: 5px 13px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.tatc-hero h1 {
    color: #ffffff !important;
    font-size: 26px;
    font-weight: 800;
    margin: 0 0 6px;
    letter-spacing: -0.02em;
}
.tatc-hero p {
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
    margin: 0;
    max-width: 700px;
    line-height: 1.55;
}
.tatc-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.tatc-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.04);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.tatc-stat-label {
    font-size: 12.5px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 4px;
}
.tatc-stat-value {
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}
.tatc-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(220, 38, 38, 0.08);
    color: #dc2626;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.tatc-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
    margin-bottom: 24px;
}
.tatc-panel-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 16px;
    margin-bottom: 20px;
    border-bottom: 1px solid #f1f5f9;
}
.tatc-panel-title {
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
}
.tatc-panel-sub {
    font-size: 13px;
    color: #64748b;
    margin: 2px 0 0;
}
.tatc-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 16px;
}
.tatc-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
}
.tatc-input, .tatc-select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #f8fafc;
    padding: 10px 13px;
    font-size: 13.5px;
    color: #0f172a;
    transition: all 0.18s ease;
}
.tatc-input:focus, .tatc-select:focus {
    outline: none;
    background: #ffffff;
    border-color: #dc2626;
    box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.14);
}
.tatc-btn-red {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #b91c1c, #dc2626);
    color: #ffffff !important;
    border: none;
    border-radius: 10px;
    padding: 10px 18px;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none !important;
    cursor: pointer;
    box-shadow: 0 6px 16px rgba(185, 28, 28, 0.22);
    transition: all 0.16s ease;
}
.tatc-btn-red:hover {
    background: linear-gradient(135deg, #991b1b, #b91c1c);
    transform: translateY(-1px);
}
.tatc-btn-outline {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    color: #334155 !important;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 9px 16px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none !important;
    cursor: pointer;
    transition: all 0.16s ease;
}
.tatc-btn-outline:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a !important;
}
.tatc-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
}
.tatc-badge-pending {
    background: #fef3c7;
    color: #b45309;
}
.tatc-badge-approved, .tatc-badge-open {
    background: #dcfce7;
    color: #15803d;
}
.tatc-badge-rejected {
    background: #fee2e2;
    color: #b91c1c;
}
.tatc-badge-ongoing {
    background: #dbeafe;
    color: #1d4ed8;
}
.tatc-table-wrap {
    overflow-x: auto;
}
.tatc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
.tatc-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 12px 14px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}
.tatc-table td {
    padding: 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    color: #1e293b;
}
.tatc-table tr:hover td {
    background: #f8fafc;
}
.tatc-doc-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 9px;
    border-radius: 7px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #334155 !important;
    font-size: 11.5px;
    font-weight: 600;
    text-decoration: none !important;
    margin-right: 4px;
    margin-bottom: 4px;
}
.tatc-doc-pill:hover {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #b91c1c !important;
}
.tatc-dash-grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(440px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}
</style>
HTML;
}

/**
 * Get detailed AMTO Role info (code, label, group) considering switched roles and real roles.
 */
function amto_get_detailed_role(): array {
    global $USER, $DB;

    $shortname = '';
    if (!empty($USER->access['rsw']) && is_array($USER->access['rsw'])) {
        $switchedroleid = end($USER->access['rsw']);
        if ($switchedroleid && isset($DB)) {
            $r = $DB->get_record('role', ['id' => $switchedroleid], 'shortname');
            if ($r) {
                $shortname = $r->shortname;
            }
        }
    }

    if ($shortname === '') {
        if (function_exists('is_siteadmin') && is_siteadmin()) {
            $shortname = 'admin';
        } else if (!empty($USER->id) && isset($DB)) {
            $roles = $DB->get_fieldset_sql(
                "SELECT r.shortname FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid WHERE ra.userid = ?",
                [$USER->id]
            );
            foreach (['manager', 'certofficer', 'qa', 'editingteacher', 'teacher', 'student'] as $cand) {
                if (in_array($cand, $roles, true)) {
                    $shortname = $cand;
                    break;
                }
            }
        }
    }

    switch ($shortname) {
        case 'admin':
            return ['code' => 'R07', 'label' => 'Administrator IT (Super Admin)', 'group' => 'admin'];
        case 'manager':
            return ['code' => 'R04', 'label' => 'Training Manager AMTO', 'group' => 'admin'];
        case 'certofficer':
            return ['code' => 'R05', 'label' => 'Certification Officer AMTO', 'group' => 'admin'];
        case 'qa':
            return ['code' => 'R06', 'label' => 'Quality Assurance & Compliance (QA)', 'group' => 'qa'];
        case 'editingteacher':
            return ['code' => 'R02', 'label' => 'Instruktur Pelatihan AMTO', 'group' => 'instructor'];
        case 'teacher':
            return ['code' => 'R03', 'label' => 'Examiner / Assessor AMTO', 'group' => 'instructor'];
        default:
            return ['code' => 'R01', 'label' => 'Peserta Pelatihan / Trainee', 'group' => 'student'];
    }
}

/**
 * Render the role-specific AMTO Operational Dashboard on /my/index.php.
 */
function amto_render_operational_dashboard(): string {
    global $DB, $USER;

    amto_ensure_tables();
    $roleinfo = amto_get_detailed_role();
    $group = $roleinfo['group'];
    $role_badge = $roleinfo['code'] . ' &bull; ' . $roleinfo['label'];
    $user_fullname = fullname($USER);

    // Fetch live operational counts
    $all_regs = $DB->get_records('amto_registrations', null, 'timecreated DESC');
    $total_regs = count($all_regs);
    $pending_regs = 0;
    $approved_regs = 0;
    $my_reg = null;
    foreach ($all_regs as $r) {
        if ($r->status === 'pending') {
            $pending_regs++;
        } else if ($r->status === 'approved') {
            $approved_regs++;
        }
        if ((int)$r->userid === (int)$USER->id || strtolower($r->username) === strtolower($USER->username)) {
            $my_reg = $r;
        }
    }

    $schedules = $DB->get_records('amto_schedules', null, 'start_date ASC');
    $total_sched = count($schedules);
    $open_sched = 0;
    $ongoing_sched = 0;
    foreach ($schedules as $s) {
        if ($s->status === 'Open Registration') {
            $open_sched++;
        } else if ($s->status === 'Ongoing') {
            $ongoing_sched++;
        }
    }

    $modules = $DB->get_records('amto_repository', null, 'timecreated DESC');
    $total_mods = count($modules);
    $total_users = $DB->count_records_select('user', 'deleted = 0 AND id > 1');
    $total_cats = $DB->count_records('course_categories');

    ob_start();
    echo amto_render_shared_styles();
    ?>
    <div class="tatc-wrap" style="padding-bottom: 12px;">
        <!-- ROLE-AWARE OPERATIONAL HERO BANNER -->
        <div class="tatc-hero">
            <div>
                <div class="tatc-hero-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <span><?php echo $role_badge; ?></span>
                </div>
                <?php if ($group === 'admin'): ?>
                    <h1>Dashboard Operasional &amp; Manajemen AMTO</h1>
                    <p>Selamat datang, <strong><?php echo s($user_fullname); ?></strong>. Pantau ringkasan pendaftaran peserta baru, jadwal batch pelatihan aktif, serta distribusi modul LMS Repository CASR Part 147 secara real-time.</p>
                <?php elseif ($group === 'qa'): ?>
                    <h1>Dashboard Audit Kepatuhan &amp; Quality Assurance (QA)</h1>
                    <p>Selamat datang, <strong><?php echo s($user_fullname); ?></strong>. Pantau revisi dokumen terkendali di LMS Repository, kepatuhan pelaksanaan jadwal batch, serta jejak audit (Audit Trail) sistem AMTO.</p>
                <?php elseif ($group === 'instructor'): ?>
                    <h1>Dashboard Penugasan Mengajar &amp; Evaluasi AMTO</h1>
                    <p>Selamat datang, <strong><?php echo s($user_fullname); ?></strong>. Berikut adalah ringkasan jadwal kelas teori dan praktik hangar, lokasi ruangan, serta pengelolaan modul materi pembelajaran Anda.</p>
                <?php else: ?>
                    <h1>Dashboard Pembelajaran &amp; Jadwal Pelatihan Saya</h1>
                    <p>Selamat datang, <strong><?php echo s($user_fullname); ?></strong>. Akses jadwal batch pelatihan Anda, pantau status berkas registrasi, dan unduh modul Training Manual resmi di LMS Repository.</p>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <?php if ($group === 'admin'): ?>
                    <a href="/amto/registrasi.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                        <span>Verifikasi Peserta (<?php echo $pending_regs; ?> Pending)</span>
                    </a>
                    <a href="/amto/jadwal.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Kelola Jadwal &amp; Batch</span>
                    </a>
                    <a href="/amto/repository.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>LMS Repository</span>
                    </a>
                <?php elseif ($group === 'qa'): ?>
                    <a href="/report/log/index.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                        <span>Buka Audit Trail (Logs)</span>
                    </a>
                    <a href="/amto/repository.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Cek Revisi Dokumen Repository</span>
                    </a>
                <?php elseif ($group === 'instructor'): ?>
                    <a href="/amto/repository.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                        <span>Unggah Modul Repository</span>
                    </a>
                    <a href="/amto/jadwal.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Jadwal Mengajar &amp; Ruangan</span>
                    </a>
                <?php else: ?>
                    <a href="/amto/repository.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                        <span>Buka Modul Materi (Repository)</span>
                    </a>
                    <a href="/amto/jadwal.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Jadwal Batch Pelatihan</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ROLE-SPECIFIC KPI SUMMARY CARDS -->
        <?php if ($group === 'admin'): ?>
        <div class="tatc-stats-grid">
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Registrasi Peserta Masuk</div>
                    <div class="tatc-stat-value"><?php echo $total_regs; ?> <span style="font-size:13px; font-weight:600; color:#d97706;">(<?php echo $pending_regs; ?> Pending)</span></div>
                </div>
                <div class="tatc-stat-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                </div>
            </div>
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Batch &amp; Jadwal Pelatihan</div>
                    <div class="tatc-stat-value" style="color:#15803d;"><?php echo $total_sched; ?> Batch <span style="font-size:13px; font-weight:600; color:#64748b;">(<?php echo $open_sched; ?> Open)</span></div>
                </div>
                <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
            </div>
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Modul LMS Repository</div>
                    <div class="tatc-stat-value" style="color:#1d4ed8;"><?php echo $total_mods; ?> Modul <span style="font-size:13px; font-weight:600; color:#64748b;">(<?php echo $total_cats; ?> Kategori)</span></div>
                </div>
                <div class="tatc-stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                </div>
            </div>
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Akun Pengguna Aktif (RBAC)</div>
                    <div class="tatc-stat-value"><?php echo $total_users; ?> Akun</div>
                </div>
                <div class="tatc-stat-icon" style="background:#fef3c7; color:#d97706;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
            </div>
        </div>
        <?php elseif ($group === 'instructor' || $group === 'qa'): ?>
        <div class="tatc-stats-grid">
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Jadwal Batch Pelatihan Aktif</div>
                    <div class="tatc-stat-value"><?php echo $total_sched; ?> Batch</div>
                </div>
                <div class="tatc-stat-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
            </div>
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Modul Materi di Repository</div>
                    <div class="tatc-stat-value" style="color:#15803d;"><?php echo $total_mods; ?> Modul</div>
                </div>
                <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                </div>
            </div>
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Kategori Kurikulum CASR 147</div>
                    <div class="tatc-stat-value" style="color:#1d4ed8;"><?php echo $total_cats; ?> Kategori</div>
                </div>
                <div class="tatc-stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="tatc-stats-grid">
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Status Akun Peserta</div>
                    <div class="tatc-stat-value" style="color:#15803d; font-size:20px;">
                        <?php echo $my_reg ? s(strtoupper($my_reg->status)) : 'AKTIF & TERVERIFIKASI'; ?>
                    </div>
                </div>
                <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
            </div>
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Jadwal Batch Tersedia</div>
                    <div class="tatc-stat-value"><?php echo $total_sched; ?> Batch</div>
                </div>
                <div class="tatc-stat-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
            </div>
            <div class="tatc-stat-card">
                <div>
                    <div class="tatc-stat-label">Modul Materi Siap Diunduh</div>
                    <div class="tatc-stat-value" style="color:#1d4ed8;"><?php echo $total_mods; ?> Modul</div>
                </div>
                <div class="tatc-stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- OPERATIONAL SUMMARY PANELS: JADWAL TERDEKAT & AKTIVITAS / MATERI TERBARU -->
        <div class="tatc-dash-grid-2">
            <!-- LEFT PANEL: JADWAL PELATIHAN TERDEKAT -->
            <div class="tatc-panel" style="margin-bottom:0;">
                <div class="tatc-panel-header">
                    <div>
                        <h2 class="tatc-panel-title">Jadwal &amp; Batch Pelatihan Terdekat</h2>
                        <p class="tatc-panel-sub">Daftar pelaksanaan kelas teori (Classroom) dan praktik (Hangar) terdekat.</p>
                    </div>
                    <a href="/amto/jadwal.php" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;">Lihat Semua Jadwal</a>
                </div>
                <?php if (empty($schedules)): ?>
                    <p class="text-muted" style="font-size:13.5px;">Belum ada jadwal batch yang terdaftar.</p>
                <?php else: ?>
                    <div class="tatc-table-wrap">
                        <table class="tatc-table">
                            <thead>
                                <tr>
                                    <th>Batch &amp; Program</th>
                                    <th>Ruangan &amp; Instruktur</th>
                                    <th>Mulai</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $count_s = 0;
                                foreach ($schedules as $s):
                                    if ($count_s++ >= 3) break;
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color:#b91c1c; font-size:12.5px;"><?php echo s($s->batch_code); ?></strong>
                                        <div style="font-weight:700; font-size:13px; color:#0f172a;"><?php echo s($s->program_name); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size:12.5px; font-weight:600;"><?php echo s($s->room_location); ?></div>
                                        <div style="font-size:11.5px; color:#64748b;"><?php echo s($s->instructor_name); ?></div>
                                    </td>
                                    <td style="font-size:12.5px; font-weight:600; white-space:nowrap;"><?php echo s($s->start_date); ?></td>
                                    <td>
                                        <span class="tatc-badge <?php echo ($s->status === 'Open Registration') ? 'tatc-badge-open' : 'tatc-badge-ongoing'; ?>">
                                            <?php echo s($s->status); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT PANEL: ROLE-SPECIFIC QUICK PANEL (REGISTRATIONS / REPOSITORY) -->
            <div class="tatc-panel" style="margin-bottom:0;">
                <div class="tatc-panel-header">
                    <div>
                        <h2 class="tatc-panel-title">
                            <?php echo ($group === 'admin') ? 'Ringkasan Registrasi & Repository Terbaru' : 'Akses Cepat LMS Repository & Kurikulum'; ?>
                        </h2>
                        <p class="tatc-panel-sub">
                            <?php echo ($group === 'admin') ? 'Pintasan cepat pengelolaan operasional Bulan 1–2.' : 'Kategori modul pelatihan resmi CASR Part 147 TATC – AMTO.'; ?>
                        </p>
                    </div>
                    <a href="/amto/repository.php" class="tatc-btn-outline" style="padding:6px 12px; font-size:12px;">Buka Repository</a>
                </div>

                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php if ($group === 'admin'): ?>
                        <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc;">
                            <div>
                                <div style="font-weight:700; font-size:13.5px; color:#0f172a;">Meja Verifikasi Pendaftaran Peserta</div>
                                <div style="font-size:12px; color:#64748b;"><?php echo $pending_regs; ?> pendaftar menunggu verifikasi berkas, <?php echo $approved_regs; ?> telah aktif.</div>
                            </div>
                            <a href="/amto/registrasi.php" class="tatc-btn-red" style="padding:7px 14px; font-size:12px;">Buka Verifikasi</a>
                        </div>
                    <?php else: ?>
                        <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc;">
                            <div>
                                <div style="font-weight:700; font-size:13.5px; color:#0f172a;"> Data &amp; Status Registrasi Peserta</div>
                                <div style="font-size:12px; color:#64748b;">Periksa bukti pendaftaran dan berkas prasyarat pelatihan Anda.</div>
                            </div>
                            <a href="/amto/registrasi.php" class="tatc-btn-outline" style="padding:7px 14px; font-size:12px;">Lihat Data Saya</a>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc;">
                        <div>
                            <div style="font-weight:700; font-size:13.5px; color:#0f172a;">Katalog Modul LMS Repository (CASR Part 147)</div>
                            <div style="font-size:12px; color:#64748b;">Basic License Cat A, Cat B1, Cat B2, Aircraft Type Rating, &amp; Mandatory Training.</div>
                        </div>
                        <a href="/amto/repository.php" class="tatc-btn-outline" style="padding:7px 14px; font-size:12px;">Lihat Modul</a>
                    </div>

                    <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc;">
                        <div>
                            <div style="font-weight:700; font-size:13.5px; color:#0f172a;">Kalender Akademik &amp; Sesi Pelatihan</div>
                            <div style="font-size:12px; color:#64748b;">Lihat jadwal bulanan sesi kelas teori dan praktik hangar TATC.</div>
                        </div>
                        <a href="/calendar/view.php?view=month" class="tatc-btn-outline" style="padding:7px 14px; font-size:12px;">Buka Kalender</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

