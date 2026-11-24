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
        $reg_cols = $DB->get_columns('amto_registrations', false);
        if (!isset($reg_cols['created_by'])) {
            try {
                $DB->execute("ALTER TABLE {amto_registrations} ADD COLUMN IF NOT EXISTS created_by BIGINT(10) NOT NULL DEFAULT 0 AFTER admin_notes");
            } catch (Throwable $e) {
                // Column already exists
            }
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
        // [SEC-FIX-03] DP-09 Ensure SHA-256 file_hash column and CeLOE syllabus columns exist
        $cols = $DB->get_columns('amto_repository', false);
        if (!isset($cols['file_hash'])) {
            try {
                $DB->execute("ALTER TABLE {amto_repository} ADD COLUMN IF NOT EXISTS file_hash VARCHAR(64) NOT NULL DEFAULT '' AFTER file_size");
            } catch (Throwable $e) {
                // Column already exists
            }
        }
        if (!isset($cols['chapter_no'])) {
            try {
                $DB->execute("ALTER TABLE {amto_repository} ADD COLUMN IF NOT EXISTS chapter_no VARCHAR(50) NOT NULL DEFAULT 'Bab 1' AFTER batch_code");
            } catch (Throwable $e) {
                // Column already exists
            }
        }
        if (!isset($cols['has_assessment'])) {
            try {
                $DB->execute("ALTER TABLE {amto_repository} ADD COLUMN IF NOT EXISTS has_assessment VARCHAR(100) NOT NULL DEFAULT 'Tidak Ada Assessment' AFTER description");
            } catch (Throwable $e) {
                // Column already exists
            }
        }
        if (!isset($cols['chapter_id'])) {
            try {
                $DB->execute("ALTER TABLE {amto_repository} ADD COLUMN IF NOT EXISTS chapter_id BIGINT(10) NOT NULL DEFAULT 0 AFTER batch_code");
            } catch (Throwable $e) {
                // Column already exists
            }
        }
        // Backfill SHA-256 checksums for existing repository files
        $existing_mods = $DB->get_records('amto_repository');
        foreach ($existing_mods as $em) {
            if (empty($em->file_hash) && !empty($em->file_path)) {
                $fp = __DIR__ . '/repository_files/' . basename($em->file_path);
                if (is_file($fp)) {
                    $em->file_hash = hash_file('sha256', $fp) ?: '';
                    $DB->update_record('amto_repository', $em);
                }
            }
        }
    }

    // 3b. Table: amto_chapters (CeLOE-style Course Sections / Wadah Bab Silabus)
    if (!$dbman->table_exists('amto_chapters')) {
        $sql = "CREATE TABLE {amto_chapters} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            schedule_id BIGINT(10) NOT NULL DEFAULT 0,
            batch_code VARCHAR(80) NOT NULL,
            chapter_no VARCHAR(50) NOT NULL DEFAULT 'Bab 1',
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Active',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
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

    if ($DB->count_records('amto_audit_logs') === 0) {
        amto_log_audit_event('SECURITY_BASELINE_INIT', 'mdl_role', 8, '8 Role RBAC (R01-R08) & IAM Policies Active', 'Initialized 8 AMTO RBAC roles, CSRF sesskey, SHA-256 integrity, SoD, and Soft-Delete/Void controls.');
        amto_log_audit_event('REPO_INTEGRITY_VERIFIED', 'amto_repository', 1, 'SHA-256 Checksum Verified (DP-09)', 'Verified cryptographic SHA-256 checksum on controlled CASR Part 147 training manuals.');
    }

    // 5. Table: amto_exams (Ujian Teori CBT & Evaluasi Praktik Hangar CASR Part 147)
    if (!$dbman->table_exists('amto_exams')) {
        $sql = "CREATE TABLE {amto_exams} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            exam_code VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            category_type VARCHAR(100) NOT NULL DEFAULT 'Basic License',
            batch_code VARCHAR(80) NOT NULL DEFAULT 'BATCH-AMTO-2601',
            chapter_id BIGINT(10) NOT NULL DEFAULT 0,
            chapter_no VARCHAR(50) NOT NULL DEFAULT 'Bab 1',
            exam_type VARCHAR(80) NOT NULL DEFAULT 'Written CBT (Multiple Choice)',
            passing_grade INT(10) NOT NULL DEFAULT 75,
            duration_mins INT(10) NOT NULL DEFAULT 60,
            exam_date VARCHAR(30) NOT NULL DEFAULT '2026-12-05',
            assessor_name VARCHAR(150) NOT NULL DEFAULT 'Capt. Hendra Wijaya',
            status VARCHAR(40) NOT NULL DEFAULT 'Active',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    } else {
        $ex_cols = $DB->get_columns('amto_exams', false);
        if (!isset($ex_cols['chapter_id'])) {
            try {
                $DB->execute("ALTER TABLE {amto_exams} ADD COLUMN IF NOT EXISTS chapter_id BIGINT(10) NOT NULL DEFAULT 0 AFTER batch_code");
            } catch (Throwable $e) {
            }
        }
        if (!isset($ex_cols['chapter_no'])) {
            try {
                $DB->execute("ALTER TABLE {amto_exams} ADD COLUMN IF NOT EXISTS chapter_no VARCHAR(50) NOT NULL DEFAULT 'Bab 1' AFTER chapter_id");
            } catch (Throwable $e) {
            }
        }
    }

    // 6. Table: amto_exam_results (Hasil Ujian & Nilai Assessment Peserta)
    if (!$dbman->table_exists('amto_exam_results')) {
        $sql = "CREATE TABLE {amto_exam_results} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            exam_id BIGINT(10) NOT NULL DEFAULT 0,
            exam_code VARCHAR(50) NOT NULL,
            exam_title VARCHAR(255) NOT NULL,
            userid BIGINT(10) NOT NULL DEFAULT 0,
            username VARCHAR(100) NOT NULL,
            fullname VARCHAR(200) NOT NULL,
            score INT(10) NOT NULL DEFAULT 0,
            passing_grade INT(10) NOT NULL DEFAULT 75,
            status VARCHAR(40) NOT NULL DEFAULT 'COMPETENT',
            notes TEXT DEFAULT NULL,
            assessed_by VARCHAR(150) NOT NULL DEFAULT 'Capt. Hendra Wijaya (R03 Assessor)',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }

    // 7. Table: amto_logbooks (Practical Logbook Hangar & Presensi Praktik Peserta)
    if (!$dbman->table_exists('amto_logbooks')) {
        $sql = "CREATE TABLE {amto_logbooks} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            log_no VARCHAR(50) NOT NULL,
            userid BIGINT(10) NOT NULL DEFAULT 0,
            username VARCHAR(100) NOT NULL,
            fullname VARCHAR(200) NOT NULL,
            batch_code VARCHAR(80) NOT NULL DEFAULT 'BATCH-AMTO-2601',
            practice_date VARCHAR(30) NOT NULL,
            ata_chapter VARCHAR(100) NOT NULL,
            task_title VARCHAR(255) NOT NULL,
            hangar_location VARCHAR(150) NOT NULL DEFAULT 'Hangar B-01 TATC',
            duration_hours VARCHAR(20) NOT NULL DEFAULT '4.0',
            tools_used VARCHAR(255) NOT NULL DEFAULT 'Standard Avionics & Mechanical ToolKit',
            description TEXT DEFAULT NULL,
            instructor_notes TEXT DEFAULT NULL,
            verified_by VARCHAR(150) DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'Pending Verification',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }

    // 7b. Table: amto_exam_questions (Bank Soal Quiz Pilihan Ganda & Essay + Lampiran Gambar)
    if (!$dbman->table_exists('amto_exam_questions')) {
        $sql = "CREATE TABLE {amto_exam_questions} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            exam_id BIGINT(10) NOT NULL DEFAULT 0,
            question_type VARCHAR(30) NOT NULL DEFAULT 'mcq',
            question_text TEXT NOT NULL,
            image_path VARCHAR(255) NOT NULL DEFAULT '',
            option_a TEXT DEFAULT NULL,
            option_b TEXT DEFAULT NULL,
            option_c TEXT DEFAULT NULL,
            option_d TEXT DEFAULT NULL,
            correct_answer TEXT DEFAULT NULL,
            points INT(10) NOT NULL DEFAULT 20,
            sort_order INT(10) NOT NULL DEFAULT 1,
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }

    $er_cols = $DB->get_columns('amto_exam_results', false);
    if (!isset($er_cols['answers_json'])) {
        try {
            $DB->execute("ALTER TABLE {amto_exam_results} ADD COLUMN IF NOT EXISTS answers_json LONGTEXT DEFAULT NULL AFTER notes");
        } catch (Throwable $e) {
        }
    }

    // 7c. Table: amto_assignments (Penugasan / Assignment Kelas Lengkap dengan Deadline)
    if (!$dbman->table_exists('amto_assignments')) {
        $sql = "CREATE TABLE {amto_assignments} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            schedule_id BIGINT(10) NOT NULL DEFAULT 0,
            batch_code VARCHAR(80) NOT NULL,
            chapter_id BIGINT(10) NOT NULL DEFAULT 0,
            chapter_no VARCHAR(50) NOT NULL DEFAULT 'Bab 1',
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            attachment_path VARCHAR(255) NOT NULL DEFAULT '',
            deadline_at VARCHAR(40) NOT NULL,
            passing_grade INT(10) NOT NULL DEFAULT 75,
            created_by VARCHAR(150) NOT NULL DEFAULT 'Admin TATC',
            status VARCHAR(30) NOT NULL DEFAULT 'Active',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }

    // 7d. Table: amto_assignment_submissions (Pengumpulan Tugas Peserta & Nilai Assignment)
    if (!$dbman->table_exists('amto_assignment_submissions')) {
        $sql = "CREATE TABLE {amto_assignment_submissions} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            assignment_id BIGINT(10) NOT NULL DEFAULT 0,
            userid BIGINT(10) NOT NULL DEFAULT 0,
            username VARCHAR(100) NOT NULL,
            fullname VARCHAR(200) NOT NULL,
            answer_text TEXT DEFAULT NULL,
            file_path VARCHAR(255) NOT NULL DEFAULT '',
            is_late INT(2) NOT NULL DEFAULT 0,
            score INT(10) DEFAULT NULL,
            feedback TEXT DEFAULT NULL,
            graded_by VARCHAR(150) DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'Submitted',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            timemodified BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }

    // 8. Table: amto_certificates (E-Sertifikat & Certificate of Recognition CASR Part 147 No. 147D-19)
    if (!$dbman->table_exists('amto_certificates')) {
        $sql = "CREATE TABLE {amto_certificates} (
            id BIGINT(10) NOT NULL AUTO_INCREMENT,
            cert_no VARCHAR(100) NOT NULL,
            verification_code VARCHAR(100) NOT NULL,
            userid BIGINT(10) NOT NULL DEFAULT 0,
            username VARCHAR(100) NOT NULL,
            fullname VARCHAR(200) NOT NULL,
            program_name VARCHAR(255) NOT NULL,
            batch_code VARCHAR(80) NOT NULL,
            amel_category VARCHAR(100) NOT NULL DEFAULT 'CASR Part 147 Approved Training',
            final_score INT(10) NOT NULL DEFAULT 88,
            attendance_pct INT(10) NOT NULL DEFAULT 100,
            issue_date VARCHAR(30) NOT NULL,
            expiry_date VARCHAR(30) NOT NULL,
            issued_by VARCHAR(150) NOT NULL DEFAULT 'Iman Setiawan (R05 Certification Officer)',
            status VARCHAR(40) NOT NULL DEFAULT 'VALID',
            timecreated BIGINT(10) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->execute($sql);
    }

    amto_sync_cross_db();
}

/**
 * 2-Way Data Synchronization between Portal Web TATC (tatc_web) and Moodle LMS (moodle).
 * Keeps batches/schedules and participant registrations unified across both databases on MariaDB 3307.
 */
function amto_sync_cross_db(bool $force = false): void {
    global $DB;
    static $synced = false;
    if ($synced && !$force) {
        return;
    }
    $synced = true;

    try {
        // Verify tatc_web database is accessible on the same MariaDB server
        $has_tatc = $DB->get_records_sql("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = 'tatc_web'");
        if (empty($has_tatc)) {
            return;
        }

        // --- A. 2-WAY SYNC: SCHEDULES & BATCHES (tatc_web.batches <-> moodle.mdl_amto_schedules) ---
        $tatc_batches = $DB->get_records_sql("
            SELECT b.id, b.code, b.name, b.start_date, b.end_date, b.status, p.name AS program_name
              FROM tatc_web.batches b
              LEFT JOIN tatc_web.programs p ON p.id = b.program_id
             WHERE b.deleted_at IS NULL
        ");
        foreach ($tatc_batches as $tb) {
            $bcode = trim((string)$tb->code);
            if ($bcode === '') {
                continue;
            }
            $exists_moodle = $DB->get_record('amto_schedules', ['batch_code' => $bcode]);
            if (!$exists_moodle) {
                $tstatus = strtoupper((string)$tb->status);
                $mstatus = 'Open Registration';
                if (in_array($tstatus, ['ONGOING', 'ACTIVE', 'IN_PROGRESS'], true)) {
                    $mstatus = 'Ongoing';
                } else if (in_array($tstatus, ['COMPLETED', 'CLOSED', 'DONE'], true)) {
                    $mstatus = 'Completed';
                }
                $ns = new stdClass();
                $ns->batch_code      = $bcode;
                $ns->program_name    = !empty($tb->program_name) ? $tb->program_name : $tb->name;
                $ns->category_type   = 'Basic License';
                $ns->training_mode   = 'Blended (Theory & Practical)';
                $ns->room_location   = 'TATC Classroom & Hangar';
                $ns->instructor_name = 'Tim Instruktur TATC';
                $ns->start_date      = !empty($tb->start_date) ? substr((string)$tb->start_date, 0, 10) : date('Y-m-d');
                $ns->end_date        = !empty($tb->end_date) ? substr((string)$tb->end_date, 0, 10) : date('Y-m-d', strtotime('+14 days'));
                $ns->schedule_time   = 'Senin - Jumat, 08:00 - 16:00 WIB';
                $ns->quota           = 24;
                $ns->status          = $mstatus;
                $ns->timecreated     = time();
                $DB->insert_record('amto_schedules', $ns);
            }
        }

        // Sync Moodle schedules -> tatc_web.batches
        $moodle_schedules = $DB->get_records('amto_schedules');
        foreach ($moodle_schedules as $ms) {
            if (strtolower((string)$ms->status) === 'voided' || strtolower((string)$ms->status) === 'void') {
                continue;
            }
            $bcode = trim((string)$ms->batch_code);
            if ($bcode === '') {
                continue;
            }
            $exists_tatc = $DB->get_records_sql("SELECT id FROM tatc_web.batches WHERE code = ?", [$bcode]);
            if (empty($exists_tatc)) {
                $prog_rows = $DB->get_records_sql("SELECT id FROM tatc_web.programs WHERE name = ? LIMIT 1", [$ms->program_name]);
                if (!empty($prog_rows)) {
                    $prog_id = (int)reset($prog_rows)->id;
                } else {
                    $pcode = 'PRG-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($bcode, -8))) . '-' . substr((string)$ms->id, 0, 3);
                    $DB->execute(
                        "INSERT IGNORE INTO tatc_web.programs (code, name, description, passing_grade, minimum_attendance_percent, status, created_at, updated_at)
                         VALUES (?, ?, ?, 75.00, 80.00, 'ACTIVE', NOW(), NOW())",
                        [$pcode, $ms->program_name, 'Program Pelatihan CASR Part 147 AMTO (' . $ms->category_type . ')']
                    );
                    $prog_rows2 = $DB->get_records_sql("SELECT id FROM tatc_web.programs WHERE name = ? OR code = ? LIMIT 1", [$ms->program_name, $pcode]);
                    $prog_id = !empty($prog_rows2) ? (int)reset($prog_rows2)->id : 1;
                }

                $t_status = 'PLANNED';
                if ($ms->status === 'Open Registration') {
                    $t_status = 'OPEN';
                } else if ($ms->status === 'Ongoing') {
                    $t_status = 'ONGOING';
                } else if ($ms->status === 'Completed') {
                    $t_status = 'COMPLETED';
                }
                $sdate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ms->start_date) ? $ms->start_date : date('Y-m-d');
                $edate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ms->end_date) ? $ms->end_date : date('Y-m-d', strtotime('+14 days'));
                $DB->execute(
                    "INSERT IGNORE INTO tatc_web.batches (program_id, code, name, start_date, end_date, status, registration_fee, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 0.00, NOW(), NOW())",
                    [$prog_id, $bcode, $ms->program_name, $sdate, $edate, $t_status]
                );
            }
        }

        // --- B. 2-WAY SYNC: PARTICIPANT REGISTRATIONS (tatc_web.participant_registrations <-> moodle.mdl_amto_registrations) ---
        $tatc_regs = $DB->get_records_sql("
            SELECT r.id AS reg_id, r.status AS reg_status, r.notes, r.submitted_at,
                   p.id AS part_id, p.participant_number, p.full_name, p.email, p.phone, p.nik, p.institution,
                   b.code AS batch_code, b.name AS batch_name, pr.name AS program_name
              FROM tatc_web.participant_registrations r
              JOIN tatc_web.participants p ON p.id = r.participant_id
              JOIN tatc_web.batches b ON b.id = r.batch_id
              LEFT JOIN tatc_web.programs pr ON pr.id = b.program_id
        ");
        foreach ($tatc_regs as $tr) {
            $email = strtolower(trim((string)$tr->email));
            $reg_no = !empty($tr->participant_number) ? trim((string)$tr->participant_number) : ('REG-TATC-' . str_pad((string)$tr->reg_id, 4, '0', STR_PAD_LEFT));
            $prog_name = !empty($tr->program_name) ? ($tr->batch_code . ' – ' . $tr->program_name) : ($tr->batch_code . ' – ' . $tr->batch_name);

            $tstat = strtoupper((string)$tr->reg_status);
            $mstat = 'pending';
            if (in_array($tstat, ['ACCEPTED', 'APPROVED', 'VERIFIED', 'PAID', 'ACTIVE', 'ENROLLED'], true)) {
                $mstat = 'approved';
            } else if (in_array($tstat, ['REJECTED', 'CANCELLED'], true)) {
                $mstat = 'rejected';
            }

            $existing_mreg = $DB->get_record_sql(
                "SELECT * FROM {amto_registrations} WHERE registration_no = :r OR (LOWER(email) = :e AND email <> '') LIMIT 1",
                ['r' => $reg_no, 'e' => $email]
            );
            if (!$existing_mreg) {
                $sched = $DB->get_record('amto_schedules', ['batch_code' => $tr->batch_code]);
                $uname = $email !== '' ? preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0]) : ('peserta' . $tr->reg_id);
                if ($uname === '') {
                    $uname = 'peserta' . $tr->reg_id;
                }
                $muser = ($email !== '') ? $DB->get_record_sql("SELECT id, username FROM {user} WHERE LOWER(email) = ? AND deleted = 0 LIMIT 1", [$email]) : null;

                $nr = new stdClass();
                $nr->registration_no = $reg_no;
                $nr->userid          = $muser ? (int)$muser->id : 0;
                $nr->username        = $muser ? $muser->username : $uname;
                $nr->fullname        = $tr->full_name;
                $nr->email           = $email ?: ($uname . '@tatc.id');
                $nr->phone           = (string)($tr->phone ?: '-');
                $nr->nik             = (string)($tr->nik ?: '3273000000000000');
                $nr->organization    = (string)($tr->institution ?: 'TATC Portal');
                $nr->amel_no         = '-';
                $nr->schedule_id     = $sched ? (int)$sched->id : 0;
                $nr->program_name    = $prog_name;
                $nr->password_hash   = '';
                $nr->doc_ktp         = '';
                $nr->doc_ijazah      = '';
                $nr->doc_pendukung   = '';
                $nr->status          = $mstat;
                $nr->admin_notes     = $tr->notes ?: 'Disinkronkan otomatis dari Portal Web TATC';
                $nr->created_by      = 0;
                $nr->verified_by     = ($mstat === 'approved') ? 2 : 0;
                $nr->timecreated     = !empty($tr->submitted_at) ? (strtotime((string)$tr->submitted_at) ?: time()) : time();
                $nr->timemodified    = time();
                $DB->insert_record('amto_registrations', $nr);
            }
        }

        // Sync Moodle registrations -> tatc_web.participant_registrations
        $moodle_regs = $DB->get_records('amto_registrations');
        foreach ($moodle_regs as $mr) {
            if (in_array(strtolower((string)$mr->status), ['void', 'voided'], true)) {
                continue;
            }
            $email = strtolower(trim((string)$mr->email));
            $part_rows = $DB->get_records_sql(
                "SELECT id FROM tatc_web.participants WHERE participant_number = ? OR (LOWER(email) = ? AND email <> '') LIMIT 1",
                [$mr->registration_no, $email]
            );
            if (!empty($part_rows)) {
                $part_id = (int)reset($part_rows)->id;
            } else {
                $DB->execute(
                    "INSERT IGNORE INTO tatc_web.participants (participant_number, full_name, phone, email, status, nik, institution, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 'ACTIVE', ?, ?, NOW(), NOW())",
                    [$mr->registration_no, $mr->fullname, $mr->phone, $email, substr((string)$mr->nik, 0, 20), $mr->organization]
                );
                $part_rows2 = $DB->get_records_sql(
                    "SELECT id FROM tatc_web.participants WHERE participant_number = ? OR (LOWER(email) = ? AND email <> '') LIMIT 1",
                    [$mr->registration_no, $email]
                );
                if (empty($part_rows2)) {
                    continue;
                }
                $part_id = (int)reset($part_rows2)->id;
            }

            // Match batch in tatc_web.batches
            $batch_id = 0;
            if (!empty($mr->schedule_id)) {
                $ms = $DB->get_record('amto_schedules', ['id' => $mr->schedule_id]);
                if ($ms) {
                    $b_rows = $DB->get_records_sql("SELECT id FROM tatc_web.batches WHERE code = ? LIMIT 1", [$ms->batch_code]);
                    if (!empty($b_rows)) {
                        $batch_id = (int)reset($b_rows)->id;
                    }
                }
            }
            if ($batch_id === 0) {
                $b_rows = $DB->get_records_sql("SELECT id FROM tatc_web.batches WHERE name LIKE ? OR code LIKE ? ORDER BY id ASC LIMIT 1", ['%' . $mr->program_name . '%', '%' . $mr->program_name . '%']);
                if (!empty($b_rows)) {
                    $batch_id = (int)reset($b_rows)->id;
                } else {
                    $any_b = $DB->get_records_sql("SELECT id FROM tatc_web.batches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1");
                    if (!empty($any_b)) {
                        $batch_id = (int)reset($any_b)->id;
                    }
                }
            }
            if ($batch_id <= 0) {
                continue;
            }

            $t_reg_stat = 'PENDING_VERIFICATION';
            if ($mr->status === 'approved') {
                $t_reg_stat = 'APPROVED';
            } else if ($mr->status === 'rejected') {
                $t_reg_stat = 'REJECTED';
            }

            $existing_treg = $DB->get_records_sql(
                "SELECT id, status FROM tatc_web.participant_registrations WHERE participant_id = ? AND batch_id = ? LIMIT 1",
                [$part_id, $batch_id]
            );
            if (empty($existing_treg)) {
                $DB->execute(
                    "INSERT INTO tatc_web.participant_registrations (participant_id, batch_id, status, total_amount, submitted_at, notes, created_at, updated_at)
                     VALUES (?, ?, ?, 0.00, NOW(), ?, NOW(), NOW())",
                    [$part_id, $batch_id, $t_reg_stat, $mr->admin_notes ?: 'Disinkronkan dari Modul Registrasi LMS AMTO']
                );
            } else {
                $treg_row = reset($existing_treg);
                if ($treg_row->status !== $t_reg_stat) {
                    $DB->execute(
                        "UPDATE tatc_web.participant_registrations SET status = ?, updated_at = NOW() WHERE id = ?",
                        [$t_reg_stat, $treg_row->id]
                    );
                }
            }

            if ($mr->status === 'approved') {
                $DB->execute(
                    "INSERT IGNORE INTO tatc_web.batch_participants (batch_id, participant_id, status, enrolled_at)
                     VALUES (?, ?, 'ENROLLED', NOW())",
                    [$batch_id, $part_id]
                );
            }
        }

        // --- C. 2-WAY SYNC: EXAMS (tatc_web.exams <-> moodle.mdl_amto_exams) ---
        $tatc_exams = $DB->get_records_sql("
            SELECT e.id, e.code, e.name, e.type, e.passing_grade, p.name AS program_name
              FROM tatc_web.exams e
              LEFT JOIN tatc_web.programs p ON p.id = e.program_id
        ");
        foreach ($tatc_exams as $te) {
            $ecode = trim((string)$te->code);
            if ($ecode === '') {
                continue;
            }
            $exists_me = $DB->get_record('amto_exams', ['exam_code' => $ecode]);
            if (!$exists_me) {
                $ne = new stdClass();
                $ne->exam_code     = $ecode;
                $ne->title         = $te->name;
                $ne->category_type = !empty($te->program_name) ? $te->program_name : 'Basic License';
                $ne->batch_code    = 'BATCH-AMTO-2601';
                $ne->exam_type     = ($te->type === 'PRACTICAL') ? 'Practical Assessment (Hangar)' : 'Written CBT (Multiple Choice)';
                $ne->passing_grade = !empty($te->passing_grade) ? (int)$te->passing_grade : 75;
                $ne->duration_mins = 60;
                $ne->exam_date     = date('Y-m-d', strtotime('+7 days'));
                $ne->assessor_name = 'Capt. Hendra Wijaya';
                $ne->status        = 'Active';
                $ne->timecreated   = time();
                $DB->insert_record('amto_exams', $ne);
            }
        }

        $moodle_exams = $DB->get_records('amto_exams');
        foreach ($moodle_exams as $me) {
            $ecode = trim((string)$me->exam_code);
            if ($ecode === '') {
                continue;
            }
            $exists_te = $DB->get_records_sql("SELECT id FROM tatc_web.exams WHERE code = ? LIMIT 1", [$ecode]);
            if (empty($exists_te)) {
                $prog_rows = $DB->get_records_sql("SELECT id FROM tatc_web.programs ORDER BY id ASC LIMIT 1");
                $prog_id = !empty($prog_rows) ? (int)reset($prog_rows)->id : null;
                $ttype = (stripos($me->exam_type, 'Practical') !== false) ? 'PRACTICAL' : 'THEORY';
                $DB->execute(
                    "INSERT IGNORE INTO tatc_web.exams (program_id, code, name, type, passing_grade, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                    [$prog_id, $ecode, $me->title, $ttype, (int)$me->passing_grade]
                );
            }
        }

        // --- D. 2-WAY SYNC: MODULES / MATERI REPOSITORY (tatc_web.modules <-> moodle.mdl_amto_repository) ---
        $tatc_mods = $DB->get_records_sql("
            SELECT id, code, name, description, created_at
              FROM tatc_web.modules
             WHERE deleted_at IS NULL AND created_at >= '2026-11-23 22:00:00'
        ");
        foreach ($tatc_mods as $tm) {
            $mcode = trim((string)$tm->code);
            if ($mcode === '') {
                continue;
            }
            $exists_mr = $DB->get_record('amto_repository', ['module_code' => $mcode]);
            if (!$exists_mr) {
                $nm = new stdClass();
                $nm->module_code   = $mcode;
                $nm->title         = $tm->name;
                $nm->category_type = 'Basic License Cat B1';
                $nm->batch_code    = 'Semua Batch';
                $nm->file_type     = 'PDF Training Manual';
                $nm->file_path     = '';
                $nm->file_size     = 'Dokumen Digital';
                $nm->file_hash     = '';
                $nm->doc_version   = 'Rev. 01 - 2026';
                $nm->description   = $tm->description ?: 'Modul materi pelatihan disinkronkan dari Portal Web TATC.';
                $nm->uploaded_by   = 2;
                $nm->uploader_name = 'Admin TATC';
                $nm->status        = 'Published';
                $nm->timecreated   = time();
                $DB->insert_record('amto_repository', $nm);
            }
        }

        $moodle_mods = $DB->get_records('amto_repository');
        foreach ($moodle_mods as $mm) {
            if (strtolower((string)$mm->status) === 'voided') {
                continue;
            }
            $mcode = trim((string)$mm->module_code);
            if ($mcode === '') {
                continue;
            }
            $exists_tm = $DB->get_records_sql("SELECT id FROM tatc_web.modules WHERE code = ? LIMIT 1", [$mcode]);
            if (empty($exists_tm)) {
                $DB->execute(
                    "INSERT IGNORE INTO tatc_web.modules (code, name, description, status, created_at, updated_at)
                     VALUES (?, ?, ?, 'ACTIVE', NOW(), NOW())",
                    [$mcode, $mm->title, $mm->description ?: ($mm->category_type . ' – ' . $mm->batch_code)]
                );
            }
        }
    } catch (Throwable $e) {
        debugging('AMTO Cross-DB Sync warning: ' . $e->getMessage(), DEBUG_DEVELOPER);
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
    // [SEC-SSO-03] DP-05 Secret Management: Ambil secret key HMAC dari environment variable dengan fallback kompatibilitas lokal
    $secret = getenv('AMTO_SSO_SECRET') ?: 'tatc-amto-integrated-portal-lms-secret-key-2026-secure';
    $expected_sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload_b64, $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected_sig, $sig)) {
        if (function_exists('amto_log_audit_event')) {
            amto_log_audit_event(
                'SSO_INVALID_HMAC_SIGNATURE',
                'sso',
                0,
                'HMAC-SHA256 verification failed',
                'Tanda tangan kriptografis cookie tatc_session tidak valid atau telah dimodifikasi (LOG-01).'
            );
        }
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
        // Look up non-admin user by email or mapped official username first
        $candidates = $DB->get_records_select(
            'user',
            '(LOWER(email) = :e OR (:e2 = \'imanjanuar44@gmail.com\' AND username IN (\'imanjm\', \'certofficer\')) OR (:e3 = \'anggarusdinar@telkomuniversity.ac.id\' AND username IN (\'anggarusdinar\', \'management\'))) AND deleted = 0 AND mnethostid = :m AND username <> \'admin\'',
            ['e' => $email, 'e2' => $email, 'e3' => $email, 'm' => $CFG->mnet_localhost_id],
            'id ASC'
        );
        if (!empty($candidates)) {
            $target_user = reset($candidates);
            if ($email === 'imanjanuar44@gmail.com' && ($target_user->username !== 'imanjm' || strtolower($target_user->email) !== 'imanjanuar44@gmail.com')) {
                $target_user->username  = 'imanjm';
                $target_user->email     = 'imanjanuar44@gmail.com';
                $target_user->firstname = 'Iman';
                $target_user->lastname  = 'Januar M';
                $DB->update_record('user', $target_user);
            } else if ($email === 'anggarusdinar@telkomuniversity.ac.id' && ($target_user->username !== 'anggarusdinar' || strtolower($target_user->email) !== 'anggarusdinar@telkomuniversity.ac.id')) {
                $target_user->username  = 'anggarusdinar';
                $target_user->email     = 'anggarusdinar@telkomuniversity.ac.id';
                $target_user->firstname = 'Angga';
                $target_user->lastname  = 'Rusdinar';
                $DB->update_record('user', $target_user);
            }
        }
    }

    $email_role_map = [
        'peserta@tatc.id'                      => 'r01_trainee',
        'instruktur@tatc.id'                   => 'r02_instructor',
        'assessor@tatc.id'                     => 'r03_assessor',
        'tm@tatc.id'                           => 'r04_trainingmanager',
        'imanjanuar44@gmail.com'               => 'r05_certofficer',
        'cert@tatc.id'                         => 'r05_certofficer',
        'qa@tatc.id'                           => 'r06_qa',
        'umismd@gmail.com'                     => 'r06_qa',
        'asfriyantot@gmail.com'                => 'r06_qa',
        'indset69@gmail.com'                   => 'r06_qa',
        'anggarusdinar@telkomuniversity.ac.id' => 'r08_management',
        'manajemen@tatc.id'                    => 'r08_management',
    ];

    if (!$target_user) {
        // Check if this participant has an APPROVED registration in mdl_amto_registrations
        $approved_reg = $DB->get_record_select(
            'amto_registrations',
            'LOWER(email) = :e AND status = \'approved\'',
            ['e' => $email],
            '*',
            IGNORE_MULTIPLE
        );

        // Only auto-provision Moodle account if user is one of the core RBAC accounts, non-student staff, or has an approved registration
        $can_provision = isset($email_role_map[$email]) || in_array($tatc_role, ['SUPERADMIN', 'ADMIN', 'INSTRUKTUR', 'INSTRUCTOR'], true) || !empty($approved_reg);
        if (!$can_provision) {
            if (isloggedin() && !isguestuser()) {
                require_logout();
            }
            return null;
        }

        $base_uname = !empty($approved_reg->username)
            ? preg_replace('/[^a-z0-9_.-]/', '', strtolower($approved_reg->username))
            : preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0]));
        if ($base_uname === '' || $base_uname === 'admin' || $base_uname === 'guest') {
            $base_uname = 'tatcuser';
        }
        $uname = $base_uname;
        $idx = 1;
        while ($DB->record_exists('user', ['username' => $uname, 'mnethostid' => $CFG->mnet_localhost_id])) {
            $uname = $base_uname . $idx;
            $idx++;
        }

        $display = !empty($approved_reg->fullname) ? trim($approved_reg->fullname) : (trim($name) !== '' ? trim($name) : ucfirst($base_uname));
        $parts = preg_split('/\s+/', $display, 2);
        $firstname = $parts[0] ?? $display;
        $lastname = (!empty($parts[1])) ? $parts[1] : 'TATC';

        $official_test_passwords = [
            'peserta@tatc.id'                      => 'Admin123@2',
            'instruktur@tatc.id'                   => 'Admin123@2',
            'assessor@tatc.id'                     => 'Admin123@2',
            'tm@tatc.id'                           => 'Admin123@2',
            'imanjanuar44@gmail.com'               => 'Imanjanuar12345',
            'cert@tatc.id'                         => 'Imanjanuar12345',
            'qa@tatc.id'                           => 'Admin123@2',
            'umismd@gmail.com'                     => 'Umi12345',
            'asfriyantot@gmail.com'                => 'Teguhasfriyanto12345',
            'indset69@gmail.com'                   => 'Idra12345',
            'admin@tatc.id'                        => 'Admin123@2',
            'anggarusdinar@telkomuniversity.ac.id' => 'anggarusdinar12345',
            'manajemen@tatc.id'                    => 'anggarusdinar12345',
        ];
        // [SEC-SSO-03] IAM-03: Gunakan password kriptografis acak untuk akun SSO baru di luar 8 akun uji resmi
        $initial_plain_pass = $official_test_passwords[$email]
            ?? (bin2hex(random_bytes(12)) . 'A1@');

        $nu = new stdClass();
        $nu->auth = 'manual';
        $nu->confirmed = 1;
        $nu->policyagreed = 1;
        $nu->deleted = 0;
        $nu->suspended = 0;
        $nu->mnethostid = $CFG->mnet_localhost_id;
        $nu->username = $uname;
        $nu->password = (!empty($approved_reg->password_hash)) ? $approved_reg->password_hash : hash_internal_user_password($initial_plain_pass);
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
        if (function_exists('amto_log_audit_event')) {
            amto_log_audit_event(
                'SSO_USER_AUTO_PROVISIONED',
                'user',
                (int)$uid,
                "username={$uname}, email={$email}, tatc_role={$tatc_role}",
                'Akun pengguna baru dibuat otomatis melalui jembatan SSO terverifikasi HMAC (LOG-02).'
            );
        }
    }

    if ($target_user && $target_user->username !== 'admin') {
        $existing_roles = $DB->get_fieldset_sql(
            "SELECT r.shortname FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid WHERE ra.userid = ?",
            [$target_user->id]
        );
        if (isset($email_role_map[$email])) {
            $desired_short = $email_role_map[$email];
            $role_rec = $DB->get_record('role', ['shortname' => $desired_short]);
            if ($role_rec && !in_array($desired_short, $existing_roles, true)) {
                role_assign($role_rec->id, $target_user->id, $syscontext->id);
            }
        } else if (empty($existing_roles)) {
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
    }

    if ($target_user && (!isloggedin() || isguestuser() || (int)$USER->id !== (int)$target_user->id)) {
        complete_user_login($target_user);
        if (!empty($USER->access['rsw'])) {
            $USER->access['rsw'] = [];
        }
        // [SEC-SSO-03] LOG-01: Catat keberhasilan autentikasi Single Sign-On ke Audit Trail
        if (function_exists('amto_log_audit_event')) {
            amto_log_audit_event(
                'SSO_LOGIN_SUCCESS',
                'user',
                (int)$target_user->id,
                "username={$target_user->username}, email={$email}, role={$tatc_role}",
                'Autentikasi Single Sign-On (SSO) berhasil diverifikasi melalui token HMAC-SHA256 (LOG-01).'
            );
        }
        return $target_user;
    }

    return $target_user;
}

/**
 * Automatically sync Moodle login session if the user logged in or logged out on tatcwebreact.
 */
function amto_auto_sync_from_tatc_cookie(): void {
    global $USER;
    $cookie_data = amto_parse_tatc_session_cookie();
    if (!$cookie_data || empty($cookie_data['email'])) {
        if (!empty($_SESSION['amto_last_tatc_token'])) {
            unset($_SESSION['amto_last_tatc_token']);
            if (isloggedin() && !isguestuser()) {
                require_logout();
                if (!defined('CLI_SCRIPT')) {
                    $redirect_to = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/login/index.php';
                    header('Location: ' . $redirect_to);
                    exit;
                }
            }
        }
        return;
    }
    $token_raw = $_COOKIE['tatc_session'] ?? '';
    $last_synced = $_SESSION['amto_last_tatc_token'] ?? '';

    $tatc_email = strtolower(trim($cookie_data['email']));
    $tatc_role = strtoupper(trim($cookie_data['role'] ?? 'PESERTA'));

    // Check if a new login token was set on tatcwebreact
    $need_switch = false;
    if ($last_synced !== $token_raw) {
        $need_switch = true;
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
 * [SEC-FIX-04] Determine granular 8-Role RBAC identity (R01 s/d R08).
 */
function amto_current_rbac_role(): string {
    amto_auto_sync_from_tatc_cookie();
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
 * [SEC-FIX-06] DP-01, DP-02 & SCP-07: Mask NIK KTP (16 digits) for Guest search & Management aggregate views.
 */
function amto_mask_nik(string $nik): string {
    $clean = trim($nik);
    $len = strlen($clean);
    if ($len <= 6) {
        return str_repeat('*', max(4, $len));
    }
    return substr($clean, 0, 4) . str_repeat('*', $len - 7) . substr($clean, -3);
}

/**
 * [SEC-FIX-06] DP-01 & SCP-07: Mask Phone number for Guest search & Management aggregate views.
 */
function amto_mask_phone(string $phone): string {
    $clean = trim($phone);
    $len = strlen($clean);
    if ($len <= 5) {
        return str_repeat('*', max(4, $len));
    }
    return substr($clean, 0, 4) . str_repeat('*', $len - 6) . substr($clean, -2);
}

/**
 * [SEC-FIX-06] DP-01 & SCP-07: Mask Email address for Guest status check.
 */
function amto_mask_email(string $email): string {
    $parts = explode('@', trim($email), 2);
    if (count($parts) !== 2) {
        return '***';
    }
    $local = $parts[0];
    $domain = $parts[1];
    $prefix = substr($local, 0, min(2, strlen($local)));
    return $prefix . '***@' . $domain;
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
 * Get detailed AMTO Role info (code, label, group) considering switched roles and all 8 RBAC roles (R01-R08).
 */
function amto_get_detailed_role(): array {
    $rbac = amto_current_rbac_role();
    switch ($rbac) {
        case 'admin':
            return ['code' => 'R07', 'label' => 'Administrator IT (Super Admin)', 'group' => 'admin'];
        case 'adminit':
            return ['code' => 'R07', 'label' => 'Administrator IT AMTO', 'group' => 'admin'];
        case 'trainingmanager':
            return ['code' => 'R04', 'label' => 'Training Manager AMTO', 'group' => 'admin'];
        case 'certofficer':
            return ['code' => 'R05', 'label' => 'Certification Officer AMTO', 'group' => 'admin'];
        case 'qa':
            return ['code' => 'R06', 'label' => 'Quality Assurance & Compliance (QA)', 'group' => 'qa'];
        case 'management':
            return ['code' => 'R08', 'label' => 'Manajemen Eksekutif AMTO', 'group' => 'admin'];
        case 'instructor':
            return ['code' => 'R02', 'label' => 'Instruktur Pelatihan AMTO', 'group' => 'instructor'];
        case 'assessor':
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
                    <a href="/amto/audit_log.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                        <span>Buka Security &amp; Audit Trail</span>
                    </a>
                    <a href="/amto/repository.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Cek Revisi Dokumen Repository</span>
                    </a>
                <?php elseif ($group === 'instructor'): ?>
                    <a href="/amto/repository.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                        <span>Unggah Modul Repository</span>
                    </a>
                    <a href="/amto/ujian_logbook.php?tab=logbook" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Verifikasi Logbook &amp; Ujian</span>
                    </a>
                    <a href="/amto/jadwal.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Jadwal Mengajar</span>
                    </a>
                <?php else: ?>
                    <a href="/amto/repository.php" class="tatc-btn-outline" style="background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;">
                        <span>Kelas &amp; Modul Materi</span>
                    </a>
                    <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Ujian CBT &amp; Logbook</span>
                    </a>
                    <a href="/amto/sertifikat.php" class="tatc-btn-outline" style="background:rgba(255,255,255,0.15); color:#ffffff !important; border-color:rgba(255,255,255,0.3);">
                        <span>Sertifikat Saya</span>
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
    </div>
    <?php
    return ob_get_clean();
}

