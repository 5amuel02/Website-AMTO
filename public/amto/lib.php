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
            created_by BIGINT(10) NOT NULL DEFAULT 0,
            verified_by BIGINT(10) NOT NULL DEFAULT 0,
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            timemodified BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    } else {
        // [SEC-FIX-04] SEC-03 Segregation of Duties: Ensure created_by column exists to prevent self-approval
        $reg_cols = $DB->get_columns('amto_registrations');
        if (!isset($reg_cols['created_by'])) {
            $DB->execute("ALTER TABLE {amto_registrations} ADD COLUMN created_by BIGINT(10) NOT NULL DEFAULT 0 AFTER admin_notes");
        }
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
            file_hash VARCHAR(64) NOT NULL DEFAULT '',
            doc_version VARCHAR(50) NOT NULL DEFAULT 'Rev. 01 - 2026',
            description TEXT DEFAULT NULL,
            uploaded_by BIGINT(10) NOT NULL DEFAULT 0,
            uploader_name VARCHAR(150) NOT NULL DEFAULT 'Admin TATC',
            status VARCHAR(30) NOT NULL DEFAULT 'Published',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    } else {
        // [SEC-FIX-03] DP-09 Ensure SHA-256 file_hash column exists for document integrity
        $cols = $DB->get_columns('amto_repository');
        if (!isset($cols['file_hash'])) {
            $DB->execute("ALTER TABLE {amto_repository} ADD COLUMN file_hash VARCHAR(64) NOT NULL DEFAULT '' AFTER file_size");
        }
    }

    // 4. Table: amto_audit_logs (Immutable Security Audit Trail per SEC-05 & LOG-01 s/d LOG-09)
    if (!$dbman->table_exists('amto_audit_logs')) {
        $sql = "CREATE TABLE {amto_audit_logs} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            event_code VARCHAR(60) NOT NULL,
            target_table VARCHAR(60) NOT NULL,
            target_id BIGINT(10) NOT NULL DEFAULT 0,
            actor_userid BIGINT(10) NOT NULL DEFAULT 0,
            actor_username VARCHAR(100) NOT NULL DEFAULT 'guest',
            actor_role VARCHAR(50) NOT NULL DEFAULT 'guest',
            ip_address VARCHAR(64) NOT NULL DEFAULT '',
            change_summary VARCHAR(255) NOT NULL DEFAULT '',
            details TEXT DEFAULT NULL,
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }
}

/**
 * [SEC-FIX-05] SEC-05 & LOG-01..LOG-09: Append-only Security Audit Trail logger.
 */
function amto_log_audit_event(
    string $event_code,
    string $target_table,
    int $target_id,
    string $change_summary,
    string $details = ''
): void {
    global $DB, $USER;
    try {
        $rec = new stdClass();
        $rec->event_code     = strtoupper(trim($event_code));
        $rec->target_table   = trim($target_table);
        $rec->target_id      = $target_id;
        $rec->actor_userid   = (!empty($USER->id) && isloggedin() && !isguestuser()) ? (int)$USER->id : 0;
        $rec->actor_username = (!empty($USER->username) && isloggedin() && !isguestuser()) ? (string)$USER->username : 'guest';
        $rec->actor_role     = function_exists('amto_current_rbac_role') ? amto_current_rbac_role() : 'guest';
        $rec->ip_address     = function_exists('getremoteaddr') ? (string)getremoteaddr() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $rec->change_summary = substr($change_summary, 0, 250);
        $rec->details        = $details;
        $rec->timecreated    = time();
        $DB->insert_record('amto_audit_logs', $rec);
    } catch (Throwable $e) {
        // Prevent logging exception from breaking transaction flow while preserving error log
        debugging('AMTO Audit Log Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Determine current effective role ('admin', 'instructor', 'student', or 'guest').
 */
function amto_current_role(): string {
    if (!isloggedin() || isguestuser()) {
        return 'guest';
    }
    if (function_exists('amto_get_effective_role')) {
        return amto_get_effective_role();
    }
    return is_siteadmin() ? 'admin' : 'student';
}

/**
 * [SEC-FIX-04] Determine granular 8-Role RBAC identity (R01 s/d R08).
 */
function amto_current_rbac_role(): string {
    if (!isloggedin() || isguestuser()) {
        return 'guest';
    }
    if (function_exists('amto_get_granular_rbac_role')) {
        return amto_get_granular_rbac_role();
    }
    return is_siteadmin() ? 'admin' : 'student';
}

/**
 * [SEC-FIX-04] Official 8-Role Catalogue per Form Kesepakatan Security & RBAC Website AMTO.
 */
function amto_get_rbac_catalogue(): array {
    return [
        'student'         => ['id' => 'R01', 'shortname' => 'r01_trainee',         'name' => 'Peserta / Trainee (R01)',         'scope' => 'SCP-01: Hanya data milik sendiri'],
        'instructor'      => ['id' => 'R02', 'shortname' => 'r02_instructor',      'name' => 'Instruktur (R02)',                'scope' => 'SCP-02: Kelas & materi penugasan'],
        'assessor'        => ['id' => 'R03', 'shortname' => 'r03_assessor',        'name' => 'Examiner / Assessor (R03)',       'scope' => 'SCP-03: Ujian & assessment otorisasi'],
        'trainingmanager' => ['id' => 'R04', 'shortname' => 'r04_trainingmanager', 'name' => 'Training Manager (R04)',          'scope' => 'SCP-04: Program, jadwal & verifikasi peserta'],
        'certofficer'     => ['id' => 'R05', 'shortname' => 'r05_certofficer',     'name' => 'Certification Officer (R05)',     'scope' => 'SCP-05: Verifikasi berkas & sertifikasi'],
        'qa'              => ['id' => 'R06', 'shortname' => 'r06_qa',              'name' => 'Quality Assurance / QA (R06)',    'scope' => 'SCP-06: Read-Only Audit & dokumen terkendali'],
        'adminit'         => ['id' => 'R07', 'shortname' => 'r07_adminit',         'name' => 'Administrator IT (R07)',          'scope' => 'SCP-08: Akun & teknis (dilarang ubah nilai/sertifikat)'],
        'management'      => ['id' => 'R08', 'shortname' => 'r08_management',      'name' => 'Manajemen AMTO (R08)',            'scope' => 'SCP-07: Dashboard agregat & persetujuan'],
        'admin'           => ['id' => 'R04/R07', 'shortname' => 'admin',           'name' => 'Super Admin / Training Mgr',      'scope' => 'Akses penuh dengan kontrol SoD & Audit Trail'],
    ];
}

/**
 * [SEC-FIX-04] SEC-01, SEC-02, SEC-04 (Deny by Default):
 * Check granular permission against the 8-Role RBAC Matrix.
 */
function amto_has_permission(string $module, string $action): bool {
    $rbac = amto_current_rbac_role();
    if ($rbac === 'admin') {
        return true;
    }
    $matrix = [
        'registrasi' => [
            'student'         => ['read_own', 'create'],
            'instructor'      => ['read_limited'],
            'assessor'        => ['read_limited'],
            'trainingmanager' => ['read_all', 'create', 'update', 'approve', 'reject', 'void'],
            'certofficer'     => ['read_all', 'verify', 'approve', 'reject'],
            'qa'              => ['read_audit'],
            'adminit'         => ['read_tech'],
            'management'      => ['read_aggregate'],
            'guest'           => ['create', 'read_search_masked'],
        ],
        'jadwal' => [
            'student'         => ['read_own'],
            'instructor'      => ['read_assigned'],
            'assessor'        => ['read_assigned'],
            'trainingmanager' => ['read_all', 'create', 'update', 'void'],
            'certofficer'     => ['read_all'],
            'qa'              => ['read_audit'],
            'adminit'         => ['read_tech'],
            'management'      => ['read_aggregate'],
            'guest'           => ['read_all'],
        ],
        'repository' => [
            'student'         => ['read', 'download'],
            'instructor'      => ['read', 'download', 'create', 'update'],
            'assessor'        => ['read', 'download'],
            'trainingmanager' => ['read', 'download', 'create', 'update', 'approve', 'void'],
            'certofficer'     => ['read', 'download'],
            'qa'              => ['read_audit', 'download', 'approve', 'void'],
            'adminit'         => ['read_tech'],
            'management'      => ['read_aggregate'],
            'guest'           => [],
        ],
        'audit_log' => [
            'trainingmanager' => ['read_limited'],
            'certofficer'     => ['read_limited'],
            'qa'              => ['read_all', 'export'],
            'adminit'         => ['read_tech'],
            'management'      => ['read_summary'],
        ],
    ];
    $allowed = $matrix[$module][$rbac] ?? [];
    return in_array($action, $allowed, true);
}

/**
 * [SEC-FIX-04] SEC-03 Segregation of Duties (SoD):
 * Prevent self-approval when the creator of a transaction attempts to approve it themselves.
 */
function amto_check_sod_approval(int $creator_userid, int $approver_userid): bool {
    if ($creator_userid <= 0 || $approver_userid <= 0) {
        return true; // Self-registration by external guest (userid=0) can be approved by authorized staff
    }
    return $creator_userid !== $approver_userid;
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
</style>
HTML;
}
