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
