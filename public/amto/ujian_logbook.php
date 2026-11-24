<?php
/**
 * AMTO Module: Quiz & Ujian Kompetensi (Pilihan Ganda / Essay + Lampiran Gambar),
 * Assignment (Penugasan + Deadline), Practical Logbook Hangar & Hasil Assessment CASR Part 147.
 */
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/lib.php');

amto_auto_sync_from_tatc_cookie();
require_login();
amto_ensure_tables();

global $DB, $USER, $PAGE, $OUTPUT;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/amto/ujian_logbook.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('TATC Portal – Quiz, Assignment, Practical Logbook & Assessment AMTO');
$PAGE->set_heading('Quiz, Assignment, Practical Logbook & Assessment AMTO');

$role = amto_current_role();
$rbac_role = amto_current_rbac_role();
$role_info = amto_get_detailed_role();

$is_student = ($rbac_role === 'student');
$can_manage_exam = in_array($rbac_role, ['admin', 'trainingmanager', 'assessor', 'instructor'], true);
$can_verify_logbook = in_array($rbac_role, ['admin', 'trainingmanager', 'instructor', 'assessor'], true);
$is_readonly_audit = in_array($rbac_role, ['qa', 'management', 'certofficer', 'adminit'], true);

$active_tab = optional_param('tab', 'ujian', PARAM_ALPHA);
if (!in_array($active_tab, ['ujian', 'assignment', 'logbook', 'hasil'], true)) {
    $active_tab = 'ujian';
}
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$msg = trim(optional_param('msg', '', PARAM_TEXT));
$take_exam_id = optional_param('take_exam', 0, PARAM_INT);
$edit_exam_id = optional_param('edit_exam', 0, PARAM_INT);
$manage_questions_id = optional_param('manage_questions', 0, PARAM_INT);
$view_assign_id = optional_param('view_assign', 0, PARAM_INT);
$edit_assign_id = optional_param('edit_assign', 0, PARAM_INT);
$submit_assign_id = optional_param('submit_assign', 0, PARAM_INT);

// Helper to securely save uploaded Quiz Image or Assignment File
function amto_save_media_upload(string $field, string $prefix, array $allowed_ext): string {
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $orig_name = basename((string)$_FILES[$field]['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return '';
    }
    $bytes = (int)$_FILES[$field]['size'];
    if ($bytes <= 0 || $bytes > 15 * 1024 * 1024) {
        return '';
    }
    $tmp_path = $_FILES[$field]['tmp_name'];
    if (!is_uploaded_file($tmp_path)) {
        return '';
    }
    $upload_dir = __DIR__ . '/repository_files';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0750, true);
    }
    $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($prefix)) . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
    $dest = $upload_dir . '/' . $safe_name;
    if (move_uploaded_file($tmp_path, $dest)) {
        return '/amto/repository_files/' . $safe_name;
    }
    return '';
}

// Handle POST Actions with CSRF sesskey protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    // 1A. Admin/Teacher adds a Question (Pilihan Ganda or Essay + Optional Image) to a Quiz/Exam
    if ($action === 'add_question' && $can_manage_exam) {
        $exam_id = required_param('exam_id', PARAM_INT);
        $question_type = trim(optional_param('question_type', 'mcq', PARAM_ALPHA));
        $question_text = trim(optional_param('question_text', '', PARAM_TEXT));
        $option_a = trim(optional_param('option_a', '', PARAM_TEXT));
        $option_b = trim(optional_param('option_b', '', PARAM_TEXT));
        $option_c = trim(optional_param('option_c', '', PARAM_TEXT));
        $option_d = trim(optional_param('option_d', '', PARAM_TEXT));
        $correct_answer = trim(optional_param('correct_answer', 'A', PARAM_TEXT));
        $essay_guideline = trim(optional_param('essay_guideline', '', PARAM_TEXT));
        $points = max(1, min(100, optional_param('points', 20, PARAM_INT)));

        if ($question_text !== '') {
            $img_path = amto_save_media_upload('question_image', 'qimg_' . $exam_id, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
            $existing_q_count = $DB->count_records('amto_exam_questions', ['exam_id' => $exam_id]);

            $q = new stdClass();
            $q->exam_id        = $exam_id;
            $q->question_type  = ($question_type === 'essay') ? 'essay' : 'mcq';
            $q->question_text  = $question_text;
            $q->image_path     = $img_path;
            $q->option_a       = ($q->question_type === 'mcq') ? $option_a : '';
            $q->option_b       = ($q->question_type === 'mcq') ? $option_b : '';
            $q->option_c       = ($q->question_type === 'mcq') ? $option_c : '';
            $q->option_d       = ($q->question_type === 'mcq') ? $option_d : '';
            $q->correct_answer = ($q->question_type === 'mcq') ? strtoupper($correct_answer) : $essay_guideline;
            $q->points         = $points;
            $q->sort_order     = $existing_q_count + 1;
            $q->timecreated    = time();
            $DB->insert_record('amto_exam_questions', $q);

            $type_label = ($q->question_type === 'essay') ? 'Essay' : 'Pilihan Ganda';
            header('Location: /amto/ujian_logbook.php?tab=ujian&manage_questions=' . $exam_id . '&msg=' . urlencode("Soal {$type_label} berhasil ditambahkan ke dalam Quiz."));
            exit;
        }
    }

    // 1B. Admin/Teacher deletes a Question from a Quiz/Exam
    if ($action === 'delete_question' && $can_manage_exam) {
        $question_id = required_param('question_id', PARAM_INT);
        $exam_id = required_param('exam_id', PARAM_INT);
        $DB->delete_records('amto_exam_questions', ['id' => $question_id]);
        header('Location: /amto/ujian_logbook.php?tab=ujian&manage_questions=' . $exam_id . '&msg=' . urlencode("Butir soal berhasil dihapus dari Quiz."));
        exit;
    }

    // 1C. Student submits Online Quiz / CBT (Supports Custom Pilihan Ganda + Essay Questions!)
    if ($action === 'submit_cbt') {
        $exam_id = required_param('exam_id', PARAM_INT);
        $exam = $DB->get_record('amto_exams', ['id' => $exam_id]);
        if ($exam) {
            $custom_questions = $DB->get_records('amto_exam_questions', ['exam_id' => $exam_id], 'sort_order ASC, id ASC');
            $earned_points = 0;
            $total_points = 0;
            $has_essay = false;
            $answers_detail = [];

            if (!empty($custom_questions)) {
                $idx = 1;
                foreach ($custom_questions as $cq) {
                    $pts = max(1, (int)$cq->points);
                    $total_points += $pts;
                    $field_key = 'cq_' . $cq->id;
                    $user_ans = trim(optional_param($field_key, '', PARAM_TEXT));

                    if ($cq->question_type === 'essay') {
                        $has_essay = true;
                        // Give provisional points if non-empty essay, editable by Admin/Assessor
                        if ($user_ans !== '') {
                            $earned_points += $pts;
                        }
                        $answers_detail[] = [
                            'no'       => $idx,
                            'type'     => 'Essay',
                            'question' => $cq->question_text,
                            'answer'   => $user_ans,
                            'expected' => $cq->correct_answer ?: 'Dinilai oleh Instruktur/Assessor',
                            'points'   => $pts,
                        ];
                    } else {
                        $is_ok = (strtoupper($user_ans) === strtoupper(trim((string)$cq->correct_answer)));
                        if ($is_ok) {
                            $earned_points += $pts;
                        }
                        $answers_detail[] = [
                            'no'       => $idx,
                            'type'     => 'Pilihan Ganda',
                            'question' => $cq->question_text,
                            'answer'   => strtoupper($user_ans) . ($is_ok ? ' (Benar)' : ' (Salah)'),
                            'expected' => 'Kunci: ' . strtoupper((string)$cq->correct_answer),
                            'points'   => $is_ok ? $pts : 0,
                        ];
                    }
                    $idx++;
                }
                $score = ($total_points > 0) ? (int)round(($earned_points / $total_points) * 100) : 0;
                $notes_txt = $has_essay
                    ? "Quiz Pilihan Ganda & Essay selesai dikerjakan (Skor Sementara: {$score}/100 - Jawaban Essay dapat direview Admin/Assessor)."
                    : "Quiz Pilihan Ganda selesai dikerjakan ({$earned_points}/{$total_points} poin).";
            } else {
                $correct_answers = [
                    'q1' => 'B',
                    'q2' => 'A',
                    'q3' => 'C',
                    'q4' => 'B',
                    'q5' => 'A',
                ];
                $correct_count = 0;
                foreach ($correct_answers as $qk => $expected) {
                    $ans = optional_param($qk, '', PARAM_ALPHA);
                    if (strtoupper($ans) === $expected) {
                        $correct_count++;
                    }
                    $answers_detail[] = [
                        'no'       => substr($qk, 1),
                        'type'     => 'Pilihan Ganda',
                        'question' => 'Soal Standar CASR Part 147 #' . substr($qk, 1),
                        'answer'   => strtoupper($ans),
                        'expected' => 'Kunci: ' . $expected,
                        'points'   => (strtoupper($ans) === $expected) ? 20 : 0,
                    ];
                }
                $score = $correct_count * 20;
                $notes_txt = "CBT Online selesai dikerjakan ({$correct_count}/5 jawaban benar).";
            }

            $status = ($score >= (int)$exam->passing_grade) ? 'LULUS / KOMPETEN' : 'BELUM LULUS (RE-EXAM)';

            $res = new stdClass();
            $res->exam_id       = (int)$exam->id;
            $res->exam_code     = $exam->exam_code;
            $res->exam_title    = $exam->title;
            $res->userid        = (int)$USER->id;
            $res->username      = $USER->username;
            $res->fullname      = fullname($USER);
            $res->score         = $score;
            $res->passing_grade = (int)$exam->passing_grade;
            $res->status        = $status;
            $res->notes         = $notes_txt;
            $res->answers_json  = json_encode($answers_detail, JSON_UNESCAPED_UNICODE);
            $res->assessed_by   = $exam->assessor_name . ($has_essay ? ' (Essay & Auto-Graded)' : ' (Auto-Graded CBT Engine)');
            $res->timecreated   = time();
            $new_id = $DB->insert_record('amto_exam_results', $res);

            amto_log_audit_event('CBT_EXAM_SUBMITTED', 'amto_exam_results', (int)$new_id, "exam={$exam->exam_code}, score={$score}, status={$status}", "Participant {$USER->username} completed quiz {$exam->title}");
            header('Location: /amto/ujian_logbook.php?tab=hasil&msg=' . urlencode("Quiz / Ujian {$exam->exam_code} berhasil dikirim! Nilai Anda: {$score}/100 ({$status})."));
            exit;
        }
    }

    // 1D. Admin/Assessor updates score of an existing Quiz/Essay result
    if ($action === 'update_result_score' && $can_manage_exam) {
        $result_id = required_param('result_id', PARAM_INT);
        $new_score = max(0, min(100, required_param('score', PARAM_INT)));
        $new_notes = trim(optional_param('notes', '', PARAM_TEXT));
        $er = $DB->get_record('amto_exam_results', ['id' => $result_id]);
        if ($er) {
            $er->score = $new_score;
            $er->status = ($new_score >= (int)$er->passing_grade) ? 'LULUS / KOMPETEN' : 'BELUM LULUS (RE-EXAM)';
            if ($new_notes !== '') {
                $er->notes = $new_notes;
            }
            $er->assessed_by = fullname($USER) . ' (' . $role_info['code'] . ')';
            $DB->update_record('amto_exam_results', $er);
            header('Location: /amto/ujian_logbook.php?tab=hasil&msg=' . urlencode("Nilai Quiz/Essay untuk {$er->fullname} berhasil diperbarui menjadi {$new_score}/100 ({$er->status})."));
            exit;
        }
    }

    // 2A. Admin/Teacher creates a new Assignment (Penugasan) with Deadline
    if ($action === 'add_assignment' && $can_manage_exam) {
        $title = trim(optional_param('title', '', PARAM_TEXT));
        $batch_code = trim(optional_param('batch_code', '', PARAM_TEXT));
        $chapter_no = trim(optional_param('chapter_no', 'Bab 1', PARAM_TEXT));
        $description = trim(optional_param('description', '', PARAM_TEXT));
        $deadline_at = trim(optional_param('deadline_at', date('Y-m-d\T23:59', strtotime('+7 days')), PARAM_TEXT));
        $passing_grade = max(50, min(100, optional_param('passing_grade', 75, PARAM_INT)));

        if ($title !== '') {
            $sched = ($batch_code !== '') ? $DB->get_record('amto_schedules', ['batch_code' => $batch_code]) : null;
            $sched_id = $sched ? (int)$sched->id : 0;
            $ch = ($sched_id > 0) ? $DB->get_record('amto_chapters', ['schedule_id' => $sched_id, 'chapter_no' => $chapter_no]) : null;
            $att_path = amto_save_media_upload('assign_file', 'assign', ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'zip', 'png', 'jpg', 'jpeg', 'webp']);

            $a = new stdClass();
            $a->schedule_id     = $sched_id;
            $a->batch_code      = $batch_code ?: 'Semua Batch';
            $a->chapter_id      = $ch ? (int)$ch->id : 0;
            $a->chapter_no      = $chapter_no ?: 'Bab 1';
            $a->title           = $title;
            $a->description     = $description;
            $a->attachment_path = $att_path;
            $a->deadline_at     = str_replace('T', ' ', $deadline_at);
            $a->passing_grade   = $passing_grade;
            $a->created_by      = fullname($USER);
            $a->status          = 'Active';
            $a->timecreated     = time();
            $DB->insert_record('amto_assignments', $a);

            header('Location: /amto/ujian_logbook.php?tab=assignment&msg=' . urlencode("Assignment (Penugasan) \"{$title}\" dengan Deadline {$a->deadline_at} WIB berhasil dibuat."));
            exit;
        }
    }

    // 2B. Admin/Teacher edits an Assignment & its Deadline
    if ($action === 'edit_assignment' && $can_manage_exam) {
        $assign_id = required_param('assign_id', PARAM_INT);
        $a = $DB->get_record('amto_assignments', ['id' => $assign_id]);
        if ($a) {
            $a->title         = trim(optional_param('title', $a->title, PARAM_TEXT));
            $a->batch_code    = trim(optional_param('batch_code', $a->batch_code, PARAM_TEXT));
            $a->chapter_no    = trim(optional_param('chapter_no', $a->chapter_no, PARAM_TEXT));
            $a->description   = trim(optional_param('description', (string)$a->description, PARAM_TEXT));
            $raw_dl           = trim(optional_param('deadline_at', $a->deadline_at, PARAM_TEXT));
            $a->deadline_at   = str_replace('T', ' ', $raw_dl);
            $a->passing_grade = max(50, min(100, optional_param('passing_grade', (int)$a->passing_grade, PARAM_INT)));
            $att_path = amto_save_media_upload('assign_file', 'assign_' . $assign_id, ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'zip', 'png', 'jpg', 'jpeg', 'webp']);
            if ($att_path !== '') {
                $a->attachment_path = $att_path;
            }
            $DB->update_record('amto_assignments', $a);
            header('Location: /amto/ujian_logbook.php?tab=assignment&msg=' . urlencode("Assignment \"{$a->title}\" & Deadline ({$a->deadline_at} WIB) berhasil diperbarui."));
            exit;
        }
    }

    // 2C. Admin/Teacher deletes an Assignment
    if ($action === 'delete_assignment' && $can_manage_exam) {
        $assign_id = required_param('assign_id', PARAM_INT);
        $a = $DB->get_record('amto_assignments', ['id' => $assign_id]);
        if ($a) {
            $a->status = 'Voided';
            $DB->update_record('amto_assignments', $a);
            $DB->delete_records('amto_assignment_submissions', ['assignment_id' => $assign_id]);
            header('Location: /amto/ujian_logbook.php?tab=assignment&msg=' . urlencode("Assignment \"{$a->title}\" telah dihapus."));
            exit;
        }
    }

    // 2D. Student submits an Assignment (Penugasan)
    if ($action === 'submit_assignment') {
        $assign_id = required_param('assign_id', PARAM_INT);
        $answer_text = trim(optional_param('answer_text', '', PARAM_TEXT));
        $asg = $DB->get_record('amto_assignments', ['id' => $assign_id]);
        if ($asg) {
            $file_path = amto_save_media_upload('submission_file', 'sub_' . $assign_id . '_' . $USER->username, ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'zip', 'png', 'jpg', 'jpeg', 'webp']);
            $dl_ts = strtotime($asg->deadline_at);
            $is_late = ($dl_ts && time() > $dl_ts) ? 1 : 0;

            $existing_sub = $DB->get_record('amto_assignment_submissions', ['assignment_id' => $assign_id, 'userid' => $USER->id]);
            if ($existing_sub) {
                $existing_sub->answer_text  = $answer_text;
                if ($file_path !== '') {
                    $existing_sub->file_path = $file_path;
                }
                $existing_sub->is_late      = $is_late;
                $existing_sub->timemodified = time();
                $DB->update_record('amto_assignment_submissions', $existing_sub);
            } else {
                $s = new stdClass();
                $s->assignment_id = $assign_id;
                $s->userid        = (int)$USER->id;
                $s->username      = $USER->username;
                $s->fullname      = fullname($USER);
                $s->answer_text   = $answer_text;
                $s->file_path     = $file_path;
                $s->is_late       = $is_late;
                $s->score         = null;
                $s->feedback      = 'Menunggu penilaian Instruktur / Admin.';
                $s->graded_by     = '';
                $s->status        = $is_late ? 'Submitted (Terlambat)' : 'Submitted (Tepat Waktu)';
                $s->timecreated   = time();
                $s->timemodified  = time();
                $DB->insert_record('amto_assignment_submissions', $s);
            }
            $late_note = $is_late ? ' (Dikumpulkan melewati batas waktu Deadline)' : ' (Dikumpulkan Tepat Waktu sebelum Deadline)';
            header('Location: /amto/ujian_logbook.php?tab=assignment&msg=' . urlencode("Tugas \"{$asg->title}\" berhasil dikumpulkan{$late_note}."));
            exit;
        }
    }

    // 2E. Admin/Teacher grades a Student's Assignment Submission
    if ($action === 'grade_assignment' && $can_manage_exam) {
        $sub_id = required_param('sub_id', PARAM_INT);
        $assign_id = required_param('assign_id', PARAM_INT);
        $score = max(0, min(100, required_param('score', PARAM_INT)));
        $feedback = trim(optional_param('feedback', '', PARAM_TEXT));
        $sub = $DB->get_record('amto_assignment_submissions', ['id' => $sub_id]);
        if ($sub) {
            $sub->score        = $score;
            $sub->feedback     = $feedback !== '' ? $feedback : 'Tugas telah diperiksa dan dinilai.';
            $sub->graded_by    = fullname($USER) . ' (' . $role_info['code'] . ')';
            $sub->status       = 'Dinilai (' . $score . '/100)';
            $sub->timemodified = time();
            $DB->update_record('amto_assignment_submissions', $sub);
            header('Location: /amto/ujian_logbook.php?tab=assignment&view_assign=' . $assign_id . '&msg=' . urlencode("Nilai tugas untuk {$sub->fullname} ({$score}/100) berhasil disimpan."));
            exit;
        }
    }

    // 3. Student submits a new Practical Logbook Hangar entry
    if ($action === 'add_logbook' && !$is_readonly_audit) {
        $batch_code      = trim(optional_param('batch_code', 'BATCH-AMTO-2601', PARAM_TEXT));
        $practice_date   = trim(optional_param('practice_date', date('Y-m-d'), PARAM_TEXT));
        $ata_chapter     = trim(optional_param('ata_chapter', '', PARAM_TEXT));
        $task_title      = trim(optional_param('task_title', '', PARAM_TEXT));
        $hangar_location = trim(optional_param('hangar_location', 'Hangar B-01 TATC', PARAM_TEXT));
        $duration_hours  = trim(optional_param('duration_hours', '4.0 Jam', PARAM_TEXT));
        $tools_used      = trim(optional_param('tools_used', '', PARAM_TEXT));
        $description     = trim(optional_param('description', '', PARAM_TEXT));

        if ($ata_chapter !== '' && $task_title !== '') {
            $last = $DB->get_records('amto_logbooks', null, 'id DESC', 'id', 0, 1);
            $next_no = !empty($last) ? (reset($last)->id + 1) : 1;
            $log_no = sprintf('LOG-AMTO-2026-%04d', $next_no);

            $rec = new stdClass();
            $rec->log_no           = $log_no;
            $rec->userid           = (int)$USER->id;
            $rec->username         = $USER->username;
            $rec->fullname         = fullname($USER);
            $rec->batch_code       = $batch_code;
            $rec->practice_date    = $practice_date;
            $rec->ata_chapter      = $ata_chapter;
            $rec->task_title       = $task_title;
            $rec->hangar_location  = $hangar_location;
            $rec->duration_hours   = $duration_hours;
            $rec->tools_used       = $tools_used ?: 'Standard Aircraft Maintenance ToolKit';
            $rec->description      = $description;
            $rec->instructor_notes = 'Menunggu verifikasi & sign-off Instruktur / Assessor.';
            $rec->verified_by      = '';
            $rec->status           = 'Pending Verification';
            $rec->timecreated      = time();
            $lid = $DB->insert_record('amto_logbooks', $rec);

            amto_log_audit_event('LOGBOOK_CREATE', 'amto_logbooks', (int)$lid, "log_no={$log_no}, ata={$ata_chapter}", "Created practical hangar logbook entry {$task_title}");
            header('Location: /amto/ujian_logbook.php?tab=logbook&msg=' . urlencode("Entri Practical Logbook {$log_no} ({$ata_chapter}) berhasil disimpan dan menunggu verifikasi Instruktur."));
            exit;
        }
    }

    // 4. Instructor / Assessor verifies or requests revision on a Logbook entry
    if ($action === 'verify_logbook' && $can_verify_logbook) {
        $log_id = required_param('log_id', PARAM_INT);
        $decision = required_param('decision', PARAM_TEXT);
        $notes = trim(optional_param('instructor_notes', '', PARAM_TEXT));
        $log = $DB->get_record('amto_logbooks', ['id' => $log_id]);
        if ($log) {
            $log->status = ($decision === 'approve') ? 'Verified / Kompeten' : 'Perlu Revisi';
            $log->instructor_notes = $notes !== '' ? $notes : (($decision === 'approve') ? 'Pekerjaan praktik sesuai prosedur AMM & standar keselamatan hangar.' : 'Mohon lengkapi detail referensi AMM dan alat ukur yang digunakan.');
            $log->verified_by = fullname($USER) . ' (' . $role_info['code'] . ')';
            $DB->update_record('amto_logbooks', $log);

            amto_log_audit_event('LOGBOOK_VERIFY', 'amto_logbooks', (int)$log_id, "log_no={$log->log_no}, status={$log->status}", "Verified by {$log->verified_by}");
            header('Location: /amto/ujian_logbook.php?tab=logbook&msg=' . urlencode("Status Logbook {$log->log_no} ({$log->fullname}) berhasil diperbarui menjadi {$log->status}."));
            exit;
        }
    }

    // 5. Assessor / Instructor / Training Manager adds/edits/deletes a Quiz or Exam
    if ($action === 'add_exam' && $can_manage_exam) {
        $e = new stdClass();
        $e->exam_code     = strtoupper(trim(optional_param('exam_code', '', PARAM_TEXT)));
        $e->title         = trim(optional_param('title', '', PARAM_TEXT));
        $e->category_type = trim(optional_param('category_type', 'Basic License', PARAM_TEXT));
        $e->batch_code    = trim(optional_param('batch_code', 'BATCH-AMTO-2601', PARAM_TEXT));
        $e->chapter_no    = trim(optional_param('chapter_no', 'Bab 1', PARAM_TEXT));
        $e->exam_type     = trim(optional_param('exam_type', 'Quiz Pilihan Ganda (Multiple Choice)', PARAM_TEXT));
        $e->passing_grade = max(50, min(100, optional_param('passing_grade', 75, PARAM_INT)));
        $e->duration_mins = max(15, optional_param('duration_mins', 60, PARAM_INT));
        $e->exam_date     = trim(optional_param('exam_date', date('Y-m-d'), PARAM_TEXT));
        $e->assessor_name = trim(optional_param('assessor_name', fullname($USER), PARAM_TEXT));
        $e->status        = 'Active';
        $e->timecreated   = time();

        if ($e->exam_code !== '' && $e->title !== '') {
            $eid = $DB->insert_record('amto_exams', $e);
            if (function_exists('amto_sync_cross_db')) {
                amto_sync_cross_db(true);
            }
            amto_log_audit_event('EXAM_CREATE', 'amto_exams', (int)$eid, "exam_code={$e->exam_code}", "Created exam {$e->title}");
            header('Location: /amto/ujian_logbook.php?tab=ujian&manage_questions=' . (int)$eid . '&msg=' . urlencode("Quiz / Ujian {$e->exam_code} ({$e->title}) berhasil dibuat! Silakan tambahkan butir soal Pilihan Ganda / Essay beserta gambar pada form di bawah ini."));
            exit;
        }
    }

    if ($action === 'edit_exam' && $can_manage_exam) {
        $exam_id = required_param('exam_id', PARAM_INT);
        $e = $DB->get_record('amto_exams', ['id' => $exam_id]);
        if ($e) {
            $e->exam_code     = strtoupper(trim(optional_param('exam_code', $e->exam_code, PARAM_TEXT)));
            $e->title         = trim(optional_param('title', $e->title, PARAM_TEXT));
            $e->batch_code    = trim(optional_param('batch_code', $e->batch_code, PARAM_TEXT));
            $e->chapter_no    = trim(optional_param('chapter_no', $e->chapter_no ?? 'Bab 1', PARAM_TEXT));
            $e->exam_type     = trim(optional_param('exam_type', $e->exam_type, PARAM_TEXT));
            $e->passing_grade = max(50, min(100, optional_param('passing_grade', (int)$e->passing_grade, PARAM_INT)));
            $e->duration_mins = max(15, optional_param('duration_mins', (int)$e->duration_mins, PARAM_INT));
            $e->exam_date     = trim(optional_param('exam_date', $e->exam_date, PARAM_TEXT));
            $e->assessor_name = trim(optional_param('assessor_name', $e->assessor_name, PARAM_TEXT));
            $DB->update_record('amto_exams', $e);
            amto_log_audit_event('EXAM_UPDATE', 'amto_exams', (int)$exam_id, "exam_code={$e->exam_code}", "Updated exam {$e->title}");
            header('Location: /amto/ujian_logbook.php?tab=ujian&msg=' . urlencode("Data Quiz / Ujian [{$e->exam_code}] {$e->title} berhasil diperbarui."));
            exit;
        }
    }

    if ($action === 'delete_exam' && $can_manage_exam) {
        $exam_id = required_param('exam_id', PARAM_INT);
        $e = $DB->get_record('amto_exams', ['id' => $exam_id]);
        if ($e) {
            $e->status = 'Voided';
            $DB->update_record('amto_exams', $e);
            $DB->delete_records('amto_exam_results', ['exam_id' => $exam_id]);
            $DB->delete_records('amto_exam_questions', ['exam_id' => $exam_id]);
            try {
                $DB->execute("DELETE FROM tatc_web.exams WHERE code = ?", [$e->exam_code]);
            } catch (Throwable $err) {
            }
            amto_log_audit_event('EXAM_VOID', 'amto_exams', (int)$exam_id, 'status=Voided', "Exam [{$e->exam_code}] voided by " . fullname($USER));
            header('Location: /amto/ujian_logbook.php?tab=ujian&msg=' . urlencode("Quiz / Ujian [{$e->exam_code}] {$e->title} beserta soal & nilai terkait telah dihapus."));
            exit;
        }
    }

    if ($action === 'input_grade' && $can_manage_exam) {
        $exam_id = required_param('exam_id', PARAM_INT);
        $student_username = trim(optional_param('student_username', 'peserta', PARAM_TEXT));
        $score = max(0, min(100, optional_param('score', 80, PARAM_INT)));
        $notes = trim(optional_param('notes', '', PARAM_TEXT));

        $exam = $DB->get_record('amto_exams', ['id' => $exam_id]);
        $suser = $DB->get_record('user', ['username' => $student_username, 'deleted' => 0]);
        if ($exam) {
            $status = ($score >= (int)$exam->passing_grade) ? 'LULUS / KOMPETEN' : 'BELUM LULUS (RE-EXAM)';
            $res = new stdClass();
            $res->exam_id       = (int)$exam->id;
            $res->exam_code     = $exam->exam_code;
            $res->exam_title    = $exam->title;
            $res->userid        = $suser ? (int)$suser->id : 0;
            $res->username      = $suser ? $suser->username : $student_username;
            $res->fullname      = $suser ? fullname($suser) : ucfirst($student_username);
            $res->score         = $score;
            $res->passing_grade = (int)$exam->passing_grade;
            $res->status        = $status;
            $res->notes         = $notes !== '' ? $notes : 'Nilai dievaluasi dan diverifikasi oleh Examiner / Assessor.';
            $res->assessed_by   = fullname($USER) . ' (' . $role_info['code'] . ')';
            $res->timecreated   = time();
            $rid = $DB->insert_record('amto_exam_results', $res);

            amto_log_audit_event('EXAM_GRADE_INPUT', 'amto_exam_results', (int)$rid, "user={$res->username}, exam={$exam->exam_code}, score={$score}", "Assessed by {$res->assessed_by}");
            header('Location: /amto/ujian_logbook.php?tab=hasil&msg=' . urlencode("Nilai Assessment untuk {$res->fullname} pada ujian {$exam->exam_code} ({$score}/100 - {$status}) berhasil disimpan."));
            exit;
        }
    }
}

// Fetch Data
$raw_exams = $DB->get_records('amto_exams', null, 'exam_date ASC, id ASC');
$exams = [];
foreach ($raw_exams as $re) {
    if (in_array(strtolower((string)$re->status), ['void', 'voided'], true)) {
        continue;
    }
    $exams[$re->id] = $re;
}

$raw_assignments = $DB->get_records('amto_assignments', null, 'id DESC');
$assignments = [];
foreach ($raw_assignments as $ra) {
    if (in_array(strtolower((string)$ra->status), ['void', 'voided'], true)) {
        continue;
    }
    $assignments[$ra->id] = $ra;
}

$raw_schedules = $DB->get_records('amto_schedules', null, 'start_date ASC');
$schedules = [];
foreach ($raw_schedules as $rs) {
    if (in_array(strtolower((string)$rs->status), ['void', 'voided'], true)) {
        continue;
    }
    $schedules[$rs->id] = $rs;
}

$editing_exam = ($edit_exam_id > 0 && isset($exams[$edit_exam_id])) ? $exams[$edit_exam_id] : null;
$managing_exam = ($manage_questions_id > 0 && isset($exams[$manage_questions_id])) ? $exams[$manage_questions_id] : null;
$selected_cbt_exam = ($take_exam_id > 0 && isset($exams[$take_exam_id])) ? $exams[$take_exam_id] : null;

$editing_assign = ($edit_assign_id > 0 && isset($assignments[$edit_assign_id])) ? $assignments[$edit_assign_id] : null;
$viewing_assign = ($view_assign_id > 0 && isset($assignments[$view_assign_id])) ? $assignments[$view_assign_id] : null;
$submitting_assign = ($submit_assign_id > 0 && isset($assignments[$submit_assign_id])) ? $assignments[$submit_assign_id] : null;

if ($is_student) {
    $raw_exam_results = $DB->get_records_select(
        'amto_exam_results',
        'userid = :uid OR LOWER(username) = :uname',
        ['uid' => $USER->id, 'uname' => strtolower($USER->username)],
        'timecreated DESC'
    );
    $logbooks = $DB->get_records_select(
        'amto_logbooks',
        'userid = :uid OR LOWER(username) = :uname',
        ['uid' => $USER->id, 'uname' => strtolower($USER->username)],
        'practice_date DESC, id DESC'
    );
} else {
    $raw_exam_results = $DB->get_records('amto_exam_results', null, 'timecreated DESC');
    $logbooks = $DB->get_records('amto_logbooks', null, 'practice_date DESC, id DESC');
}

$exam_results = [];
foreach ($raw_exam_results as $rer) {
    if (!isset($exams[$rer->exam_id])) {
        continue;
    }
    $exam_results[$rer->id] = $rer;
}

$verified_logs = 0;
$pending_logs = 0;
foreach ($logbooks as $lb) {
    if (stripos($lb->status, 'Verified') !== false) {
        $verified_logs++;
    } else {
        $pending_logs++;
    }
}

$passed_exams = 0;
foreach ($exam_results as $er) {
    if ($er->score >= $er->passing_grade) {
        $passed_exams++;
    }
}

echo $OUTPUT->header();
echo amto_render_shared_styles();
?>

<div class="tatc-wrap">
    <!-- HERO BANNER -->
    <div class="tatc-hero">
        <div>
            <div class="tatc-hero-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <span>CASR Part 147 &bull; <?php echo s($role_info['code'] . ' • ' . $role_info['label']); ?></span>
            </div>
            <h1>
                <?php if ($is_student): ?>
                    Quiz / Ujian, Assignment (Penugasan), Practical Logbook &amp; Nilai Saya
                <?php else: ?>
                    Manajemen Quiz (Pilihan Ganda &amp; Essay), Assignment, Logbook &amp; Nilai
                <?php endif; ?>
            </h1>
            <p>
                <?php if ($is_student): ?>
                    Kerjakan Quiz Pilihan Ganda maupun Essay, kumpulkan Assignment (Penugasan) sebelum batas waktu <em>Deadline</em>, catat aktivitas praktik di hangar pada <em>Practical Logbook</em>, dan pantau transkrip nilai Anda.
                <?php else: ?>
                    Buat Quiz versi <strong>Pilihan Ganda</strong> atau <strong>Essay</strong> lengkap dengan <strong>Lampiran Gambar Soal</strong>, kelola <strong>Assignment (Penugasan) beserta setting Deadline</strong>, dan verifikasi nilai peserta.
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline" style="<?php echo $active_tab === 'ujian' ? 'background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;' : 'background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);'; ?>">
                <span>1. Quiz &amp; Ujian (<?php echo count($exams); ?>)</span>
            </a>
            <a href="/amto/ujian_logbook.php?tab=assignment" class="tatc-btn-outline" style="<?php echo $active_tab === 'assignment' ? 'background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;' : 'background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);'; ?>">
                <span>2. Assignment / Penugasan (<?php echo count($assignments); ?>)</span>
            </a>
            <a href="/amto/ujian_logbook.php?tab=logbook" class="tatc-btn-outline" style="<?php echo $active_tab === 'logbook' ? 'background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;' : 'background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);'; ?>">
                <span>3. Practical Logbook</span>
            </a>
            <a href="/amto/ujian_logbook.php?tab=hasil" class="tatc-btn-outline" style="<?php echo $active_tab === 'hasil' ? 'background:#ffffff; color:#b91c1c !important; border:none; font-weight:700;' : 'background:rgba(255,255,255,0.14); color:#ffffff !important; border-color:rgba(255,255,255,0.3);'; ?>">
                <span>4. Hasil &amp; Penilaian</span>
            </a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="tatc-alert tatc-alert-success">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><?php echo s($msg); ?></div>
        </div>
    <?php endif; ?>

    <!-- KPI SUMMARY CARDS -->
    <div class="tatc-stats-grid">
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Modul Quiz &amp; Ujian Aktif</div>
                <div class="tatc-stat-value"><?php echo count($exams); ?> Ujian</div>
            </div>
            <div class="tatc-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Assignment (Penugasan)</div>
                <div class="tatc-stat-value" style="color:#6d28d9;"><?php echo count($assignments); ?> Tugas</div>
            </div>
            <div class="tatc-stat-icon" style="background:#ede9fe; color:#6d28d9;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Practical Logbook Hangar</div>
                <div class="tatc-stat-value" style="color:#15803d;"><?php echo count($logbooks); ?> Entri <span style="font-size:13px; color:#64748b; font-weight:600;">(<?php echo $verified_logs; ?> Verified)</span></div>
            </div>
            <div class="tatc-stat-icon" style="background:#dcfce7; color:#15803d;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </div>
        </div>
        <div class="tatc-stat-card">
            <div>
                <div class="tatc-stat-label">Hasil Assessment Lulus</div>
                <div class="tatc-stat-value" style="color:#1d4ed8;"><?php echo $passed_exams; ?> / <?php echo count($exam_results); ?> Kompeten</div>
            </div>
            <div class="tatc-stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
        </div>
    </div>

    <!-- PANEL BUILDER SOAL QUIZ (PILIHAN GANDA & ESSAY + LAMPIRAN GAMBAR SOAL) KHUSUS ADMIN / TEACHER -->
    <?php if ($can_manage_exam && $managing_exam): ?>
    <?php
    $exam_questions = $DB->get_records('amto_exam_questions', ['exam_id' => $managing_exam->id], 'sort_order ASC, id ASC');
    ?>
    <div class="tatc-panel" style="border: 2px solid #dc2626; background: #fffcfc;">
        <div class="tatc-panel-header">
            <div>
                <span class="tatc-badge tatc-badge-rejected" style="margin-bottom:6px;">BUILDER SOAL QUIZ &bull; <?php echo s($managing_exam->exam_code); ?></span>
                <h2 class="tatc-panel-title">Kelola Butir Soal Quiz: <?php echo s($managing_exam->title); ?></h2>
                <p class="tatc-panel-sub">Tambahkan soal versi <strong>Pilihan Ganda (Multiple Choice)</strong> atau <strong>Essay (Uraian)</strong>, serta lampirkan <strong>Gambar Soal</strong> jika diperlukan.</p>
            </div>
            <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline">Selesai &amp; Tutup Builder Soal</a>
        </div>

        <form method="post" action="/amto/ujian_logbook.php?tab=ujian&amp;manage_questions=<?php echo (int)$managing_exam->id; ?>" enctype="multipart/form-data" style="background:#ffffff; border:1px solid #fecaca; border-radius:14px; padding:18px; margin-bottom:20px;">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add_question">
            <input type="hidden" name="exam_id" value="<?php echo (int)$managing_exam->id; ?>">

            <div class="tatc-form-grid mb-3">
                <div>
                    <label class="tatc-label">Jenis / Tipe Soal *</label>
                    <select name="question_type" class="tatc-select" onchange="var isEssay=(this.value==='essay'); document.getElementById('mcqFieldsBox').style.display=(isEssay?'none':'block'); document.getElementById('essayFieldsBox').style.display=(isEssay?'block':'none');">
                        <option value="mcq">Pilihan Ganda (Multiple Choice A / B / C / D)</option>
                        <option value="essay">Essay (Jawaban Uraian / Penjelasan)</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Lampirkan Gambar pada Soal (Opsional - JPG/PNG/WEBP)</label>
                    <input type="file" name="question_image" class="tatc-input" accept=".jpg,.jpeg,.png,.webp,.gif">
                </div>
                <div>
                    <label class="tatc-label">Bobot Poin Soal *</label>
                    <input type="number" name="points" class="tatc-input" value="20" min="1" max="100" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="tatc-label">Teks Pertanyaan Soal *</label>
                <textarea name="question_text" class="tatc-textarea" rows="3" placeholder="Tuliskan pertanyaan soal Pilihan Ganda atau Essay di sini..." required></textarea>
            </div>

            <!-- KOLOM KHUSUS PILIHAN GANDA -->
            <div id="mcqFieldsBox">
                <div class="tatc-form-grid mb-3">
                    <div>
                        <label class="tatc-label">Pilihan Jawaban A *</label>
                        <input type="text" name="option_a" class="tatc-input" placeholder="Teks opsi A">
                    </div>
                    <div>
                        <label class="tatc-label">Pilihan Jawaban B *</label>
                        <input type="text" name="option_b" class="tatc-input" placeholder="Teks opsi B">
                    </div>
                    <div>
                        <label class="tatc-label">Pilihan Jawaban C *</label>
                        <input type="text" name="option_c" class="tatc-input" placeholder="Teks opsi C">
                    </div>
                    <div>
                        <label class="tatc-label">Pilihan Jawaban D *</label>
                        <input type="text" name="option_d" class="tatc-input" placeholder="Teks opsi D">
                    </div>
                    <div>
                        <label class="tatc-label">Kunci Jawaban Benar (Pilihan Ganda) *</label>
                        <select name="correct_answer" class="tatc-select">
                            <option value="A">Jawaban A</option>
                            <option value="B">Jawaban B</option>
                            <option value="C">Jawaban C</option>
                            <option value="D">Jawaban D</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- KOLOM KHUSUS ESSAY -->
            <div id="essayFieldsBox" style="display:none;">
                <div class="mb-3">
                    <label class="tatc-label">Pedoman Jawaban / Kunci Referensi Essay (Opsional untuk Panduan Penilaian)</label>
                    <input type="text" name="essay_guideline" class="tatc-input" placeholder="Contoh: Peserta wajib menjelaskan prinsip kerja hidrolik dan standar tekanan 3000 PSI.">
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="tatc-btn-red">+ Tambahkan Butir Soal ke Quiz</button>
            </div>
        </form>

        <!-- DAFTAR SOAL YANG SUDAH DITAMBAHKAN KE QUIZ INI -->
        <div>
            <h3 style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:12px;">
                Daftar Butir Soal dalam Quiz Ini (<?php echo count($exam_questions); ?> Soal)
            </h3>
            <?php if (empty($exam_questions)): ?>
                <div style="text-align:center; padding:24px; border:1px dashed #cbd5e1; border-radius:12px; color:#64748b;">
                    Belum ada soal kustom yang ditambahkan pada Quiz ini. Gunakan formulir di atas untuk menambahkan soal <strong>Pilihan Ganda</strong> atau <strong>Essay</strong> beserta lampiran gambar.
                </div>
            <?php else: ?>
                <?php $qno = 1; foreach ($exam_questions as $eq): ?>
                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:12px;">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="tatc-badge <?php echo ($eq->question_type === 'essay') ? 'tatc-badge-pending' : 'tatc-badge-ongoing'; ?>">
                                        Soal #<?php echo $qno; ?> &bull; <?php echo ($eq->question_type === 'essay') ? 'ESSAY (URAIAN)' : 'PILIHAN GANDA'; ?>
                                    </span>
                                    <span class="tatc-doc-pill" style="margin:0;">Bobot: <?php echo (int)$eq->points; ?> Poin</span>
                                </div>
                                <div style="font-weight:700; font-size:14.5px; color:#0f172a; margin-bottom:8px;">
                                    <?php echo nl2br(s($eq->question_text)); ?>
                                </div>
                                <?php if (!empty($eq->image_path)): ?>
                                    <div style="margin: 10px 0;">
                                        <img src="/amto/download.php?type=quizimg&amp;id=<?php echo (int)$eq->id; ?>" alt="Gambar Soal" style="max-height:220px; max-width:100%; border-radius:10px; border:1px solid #cbd5e1; padding:4px; background:#f8fafc;">
                                    </div>
                                <?php endif; ?>

                                <?php if ($eq->question_type === 'mcq'): ?>
                                    <div style="font-size:13px; color:#334155; display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:6px; margin-top:6px;">
                                        <div><strong>A.</strong> <?php echo s($eq->option_a); ?></div>
                                        <div><strong>B.</strong> <?php echo s($eq->option_b); ?></div>
                                        <div><strong>C.</strong> <?php echo s($eq->option_c); ?></div>
                                        <div><strong>D.</strong> <?php echo s($eq->option_d); ?></div>
                                    </div>
                                    <div style="font-size:12px; color:#15803d; font-weight:700; margin-top:6px;">
                                        Kunci Jawaban Benar: Pilihan <?php echo s($eq->correct_answer); ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size:12.5px; color:#b45309; margin-top:4px;">
                                        Pedoman Penilaian Essay: <em><?php echo s($eq->correct_answer ?: 'Dinilai manual oleh Instruktur / Assessor'); ?></em>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <form method="post" action="/amto/ujian_logbook.php?tab=ujian&amp;manage_questions=<?php echo (int)$managing_exam->id; ?>" onsubmit="return confirm('Hapus butir soal ini?');">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="action" value="delete_question">
                                <input type="hidden" name="question_id" value="<?php echo (int)$eq->id; ?>">
                                <input type="hidden" name="exam_id" value="<?php echo (int)$managing_exam->id; ?>">
                                <button type="submit" class="tatc-btn-outline" style="padding:5px 10px; font-size:12px; color:#b91c1c !important; border-color:#fecaca;">Hapus Soal</button>
                            </form>
                        </div>
                    </div>
                <?php $qno++; endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- LEMBAR PENGERJAAN QUIZ / UJIAN PESERTA (MENDUKUNG PILIHAN GANDA, ESSAY, DAN GAMBAR SOAL!) -->
    <?php if ($selected_cbt_exam): ?>
    <?php
    $cbt_questions = $DB->get_records('amto_exam_questions', ['exam_id' => $selected_cbt_exam->id], 'sort_order ASC, id ASC');
    ?>
    <div class="tatc-panel" style="border: 2px solid #dc2626; background: #fffcfc;">
        <div class="tatc-panel-header">
            <div>
                <span class="tatc-badge tatc-badge-rejected" style="margin-bottom:6px;">LEMBAR QUIZ / UJIAN AKTIF &bull; <?php echo s($selected_cbt_exam->exam_code); ?></span>
                <h2 class="tatc-panel-title"><?php echo s($selected_cbt_exam->title); ?></h2>
                <p class="tatc-panel-sub">Format: <?php echo s($selected_cbt_exam->exam_type); ?> &bull; Durasi: <?php echo (int)$selected_cbt_exam->duration_mins; ?> Menit &bull; Passing Grade: <?php echo (int)$selected_cbt_exam->passing_grade; ?>/100</p>
            </div>
            <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline">Batal / Tutup Lembar Ujian</a>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=hasil">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="submit_cbt">
            <input type="hidden" name="exam_id" value="<?php echo (int)$selected_cbt_exam->id; ?>">

            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php if (!empty($cbt_questions)): ?>
                    <?php $qno = 1; foreach ($cbt_questions as $cq): ?>
                        <div style="padding:16px 18px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="tatc-badge <?php echo ($cq->question_type === 'essay') ? 'tatc-badge-pending' : 'tatc-badge-ongoing'; ?>">
                                    Soal #<?php echo $qno; ?> &bull; <?php echo ($cq->question_type === 'essay') ? 'SOAL ESSAY (URAIAN)' : 'PILIHAN GANDA'; ?>
                                </span>
                                <span class="tatc-doc-pill" style="margin:0;">Bobot: <?php echo (int)$cq->points; ?> Poin</span>
                            </div>

                            <div style="font-weight:700; font-size:15px; color:#0f172a; margin-bottom:10px;">
                                <?php echo nl2br(s($cq->question_text)); ?>
                            </div>

                            <?php if (!empty($cq->image_path)): ?>
                                <div style="margin-bottom:12px;">
                                    <img src="/amto/download.php?type=quizimg&amp;id=<?php echo (int)$cq->id; ?>" alt="Lampiran Gambar Soal" style="max-height:280px; max-width:100%; border-radius:10px; border:1px solid #cbd5e1; padding:6px; background:#f8fafc;">
                                </div>
                            <?php endif; ?>

                            <?php if ($cq->question_type === 'essay'): ?>
                                <label class="tatc-label">Jawaban Uraian / Essay Anda *</label>
                                <textarea name="cq_<?php echo (int)$cq->id; ?>" class="tatc-textarea" rows="4" placeholder="Tuliskan jawaban uraian Anda secara lengkap di sini..." required></textarea>
                            <?php else: ?>
                                <?php foreach (['A' => $cq->option_a, 'B' => $cq->option_b, 'C' => $cq->option_c, 'D' => $cq->option_d] as $opt_key => $opt_val): ?>
                                    <?php if (trim((string)$opt_val) !== ''): ?>
                                        <label style="display:block; margin-bottom:6px; cursor:pointer;">
                                            <input type="radio" name="cq_<?php echo (int)$cq->id; ?>" value="<?php echo $opt_key; ?>" required>
                                            <strong><?php echo $opt_key; ?>.</strong> <?php echo s($opt_val); ?>
                                        </label>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php $qno++; endforeach; ?>
                <?php else: ?>
                    <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                        <div style="font-weight:700; margin-bottom:8px;">1. Berdasarkan regulasi DGCA CASR Part 147, berapakah nilai kelulusan minimum (Passing Grade) ujian modul pengetahuan dasar perawatan pesawat udara?</div>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q1" value="A" required> A. 60% dari total skor</label>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q1" value="B" checked> B. 75% dari total skor (Standar Wajib CASR Part 147)</label>
                        <label style="display:block;"><input type="radio" name="q1" value="C"> C. 50% dari total skor</label>
                    </div>
                    <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                        <div style="font-weight:700; margin-bottom:8px;">2. Nomor persetujuan resmi (Approved Certificate Number) Aircraft Maintenance Training Organization (AMTO) Telkom University Aviation Training Center dari DKPPU adalah:</div>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q2" value="A" checked required> A. AMTO Approval No. 147D-19</label>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q2" value="B"> B. AMTO Approval No. 121-01</label>
                        <label style="display:block;"><input type="radio" name="q2" value="C"> C. AMTO Approval No. 91-99</label>
                    </div>
                    <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                        <div style="font-weight:700; margin-bottom:8px;">3. Dalam kurikulum Mandatory Training perawatan aviasi, singkatan dari EWIS adalah:</div>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q3" value="A" required> A. Engine Wing Inspection System</label>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q3" value="B"> B. Electronic Weather Instrument Sensor</label>
                        <label style="display:block;"><input type="radio" name="q3" value="C" checked> C. Electrical Wiring Interconnection System</label>
                    </div>
                    <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                        <div style="font-weight:700; margin-bottom:8px;">4. Berapakah persentase kehadiran minimum peserta pada sesi teori (Classroom) dan praktik (Hangar) agar memenuhi syarat mengikuti ujian akhir AMTO?</div>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q4" value="A" required> A. Minimal 50% kehadiran</label>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q4" value="B" checked> B. Minimal 80% kehadiran (Sesuai Manual Pelatihan AMTO)</label>
                        <label style="display:block;"><input type="radio" name="q4" value="C"> C. Tidak ada batas minimum kehadiran</label>
                    </div>
                    <div style="padding:14px 16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px;">
                        <div style="font-weight:700; margin-bottom:8px;">5. Sebelum dan sesudah praktik di hangar, teknisi wajib melakukan Tool Control &amp; pemeriksaan area kerja untuk mencegah kerusakan pesawat akibat benda asing yang dikenal sebagai:</div>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q5" value="A" checked required> A. FOD (Foreign Object Damage / Debris)</label>
                        <label style="display:block; margin-bottom:4px;"><input type="radio" name="q5" value="B"> B. ILS (Instrument Landing System)</label>
                        <label style="display:block;"><input type="radio" name="q5" value="C"> C. VOR (VHF Omnidirectional Range)</label>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="tatc-btn-red">Kirim Jawaban Quiz / Ujian</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- TAB 1: DAFTAR QUIZ & UJIAN AKTIF -->
    <?php if ($active_tab === 'ujian'): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Quiz (Pilihan Ganda &amp; Essay) &amp; Evaluasi Praktik Hangar Aktif</h2>
                <p class="tatc-panel-sub">Jadwal pelaksanaan Quiz dan Ujian modul kurikulum CASR Part 147 beserta Builder Soal &amp; Lampiran Gambar.</p>
            </div>
            <span class="tatc-badge tatc-badge-open">Standar Lulus: &ge; 75 / 100</span>
        </div>

        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>Kode Quiz/Ujian</th>
                        <th>Judul Quiz &amp; Bab</th>
                        <th>Batch &amp; Kategori</th>
                        <th>Format &amp; Jumlah Soal</th>
                        <th>Tanggal &amp; Assessor</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($exams)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:32px 16px; color:#64748b;">
                            <strong>Belum ada jadwal atau modul Quiz / Ujian yang dibuat.</strong><br>
                            Silakan buat Quiz baru melalui form di bawah ini atau langsung dari dalam Bab Silabus Kelas.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($exams as $ex): ?>
                    <?php $q_count = $DB->count_records('amto_exam_questions', ['exam_id' => $ex->id]); ?>
                    <tr>
                        <td><strong style="color:#b91c1c;"><?php echo s($ex->exam_code); ?></strong></td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;"><?php echo s($ex->title); ?></div>
                            <div style="font-size:12px; color:#64748b;">
                                <span class="tatc-doc-pill" style="margin:0;"><?php echo s($ex->chapter_no ?? 'Bab 1'); ?></span> &bull;
                                Passing Grade: <strong><?php echo (int)$ex->passing_grade; ?>/100</strong>
                            </div>
                        </td>
                        <td>
                            <code><?php echo s($ex->batch_code); ?></code>
                            <div style="font-size:12px; color:#64748b;"><?php echo s($ex->category_type); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:12.5px;"><?php echo s($ex->exam_type); ?></div>
                            <div style="font-size:12px; color:#0369a1; font-weight:700;"><?php echo $q_count; ?> Butir Soal &bull; <?php echo (int)$ex->duration_mins; ?> Menit</div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:12.5px;"><?php echo s($ex->exam_date); ?></div>
                            <div style="font-size:12px; color:#64748b;"><?php echo s($ex->assessor_name); ?></div>
                        </td>
                        <td>
                            <?php if ($can_manage_exam): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <a href="/amto/ujian_logbook.php?tab=ujian&amp;manage_questions=<?php echo (int)$ex->id; ?>" class="tatc-btn-red" style="padding:6px 10px; font-size:12px;">
                                        + Soal &amp; Gambar (<?php echo $q_count; ?>)
                                    </a>
                                    <a href="/amto/ujian_logbook.php?tab=hasil" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px;">
                                        Cek Hasil
                                    </a>
                                    <a href="/amto/ujian_logbook.php?tab=ujian&amp;edit_exam=<?php echo (int)$ex->id; ?>" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#1d4ed8 !important; border-color:#bfdbfe; background:#eff6ff;">
                                        Edit
                                    </a>
                                    <form method="post" action="/amto/ujian_logbook.php?tab=ujian" style="display:inline;" onsubmit="return confirm('Hapus quiz/ujian ini?');">
                                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                        <input type="hidden" name="action" value="delete_exam">
                                        <input type="hidden" name="exam_id" value="<?php echo (int)$ex->id; ?>">
                                        <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#b91c1c !important; border-color:#fecaca; background:#fef2f2;">
                                            Hapus
                                        </button>
                                    </form>
                                </div>
                            <?php elseif ($is_student): ?>
                                <a href="/amto/ujian_logbook.php?tab=ujian&amp;take_exam=<?php echo (int)$ex->id; ?>" class="tatc-btn-red" style="padding:6px 12px; font-size:12px;">
                                    Kerjakan Quiz / Ujian
                                </a>
                            <?php else: ?>
                                <a href="/amto/ujian_logbook.php?tab=hasil" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px;">
                                    Lihat Nilai
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($can_manage_exam && $editing_exam): ?>
    <div class="tatc-panel" style="border: 2px solid #2563eb; background: #f8fafc;">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Edit Data Quiz / Ujian: [<?php echo s($editing_exam->exam_code); ?>] <?php echo s($editing_exam->title); ?></h2>
                <p class="tatc-panel-sub">Perbarui kode, judul, format (Pilihan Ganda / Essay), batch, tanggal pelaksanaan, durasi, atau nilai Passing Grade.</p>
            </div>
            <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline">Batal Edit</a>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=ujian">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="edit_exam">
            <input type="hidden" name="exam_id" value="<?php echo (int)$editing_exam->id; ?>">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Kode Quiz / Ujian *</label>
                    <input type="text" name="exam_code" class="tatc-input" value="<?php echo s($editing_exam->exam_code); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Judul Quiz / Ujian *</label>
                    <input type="text" name="title" class="tatc-input" value="<?php echo s($editing_exam->title); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Batch Pelatihan &amp; Bab</label>
                    <div class="d-flex gap-2">
                        <select name="batch_code" class="tatc-select">
                            <?php foreach ($schedules as $sc): ?>
                                <option value="<?php echo s($sc->batch_code); ?>" <?php echo ($editing_exam->batch_code === $sc->batch_code) ? 'selected' : ''; ?>><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="chapter_no" class="tatc-select" style="max-width:120px;">
                            <?php for ($bi = 1; $bi <= 12; $bi++): $bl = 'Bab ' . $bi; ?>
                                <option value="<?php echo $bl; ?>" <?php echo (($editing_exam->chapter_no ?? 'Bab 1') === $bl) ? 'selected' : ''; ?>><?php echo $bl; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="tatc-label">Format Quiz / Evaluasi</label>
                    <select name="exam_type" class="tatc-select">
                        <option value="Quiz Pilihan Ganda (Multiple Choice)" <?php echo (stripos($editing_exam->exam_type, 'Multiple Choice') !== false) ? 'selected' : ''; ?>>Pilihan Ganda (Multiple Choice)</option>
                        <option value="Quiz Essay (Uraian)" <?php echo (stripos($editing_exam->exam_type, 'Essay') !== false && stripos($editing_exam->exam_type, 'Campuran') === false) ? 'selected' : ''; ?>>Essay (Uraian)</option>
                        <option value="Quiz Campuran (Pilihan Ganda & Essay)" <?php echo (stripos($editing_exam->exam_type, 'Campuran') !== false) ? 'selected' : ''; ?>>Campuran (Pilihan Ganda &amp; Essay)</option>
                        <option value="Practical Assessment (Hangar)" <?php echo (stripos($editing_exam->exam_type, 'Practical') !== false) ? 'selected' : ''; ?>>Practical Assessment (Hangar)</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Tanggal Ujian</label>
                    <input type="date" name="exam_date" class="tatc-input" value="<?php echo s($editing_exam->exam_date); ?>">
                </div>
                <div>
                    <label class="tatc-label">Durasi (Menit) &amp; Passing Grade</label>
                    <div class="d-flex gap-2">
                        <input type="number" name="duration_mins" class="tatc-input" value="<?php echo (int)$editing_exam->duration_mins; ?>" min="15">
                        <input type="number" name="passing_grade" class="tatc-input" value="<?php echo (int)$editing_exam->passing_grade; ?>" min="50" max="100">
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex justify-content-end gap-2">
                <a href="/amto/ujian_logbook.php?tab=ujian" class="tatc-btn-outline">Batal</a>
                <button type="submit" class="tatc-btn-red">Simpan Perubahan Quiz / Ujian</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($can_manage_exam): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Buat Quiz / Ujian Baru (Pilihan Ganda atau Essay + Lampiran Gambar)</h2>
                <p class="tatc-panel-sub">Setelah membuat wadah Quiz di bawah ini, halaman akan otomatis membuka <strong>Builder Soal</strong> untuk menambahkan soal Pilihan Ganda / Essay beserta gambar.</p>
            </div>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=ujian">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add_exam">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Kode Quiz / Ujian *</label>
                    <input type="text" name="exam_code" class="tatc-input" placeholder="Contoh: QUIZ-BAB1-01" required>
                </div>
                <div>
                    <label class="tatc-label">Judul Quiz / Ujian *</label>
                    <input type="text" name="title" class="tatc-input" placeholder="Contoh: Quiz Bab 1 - Electrical Fundamentals" required>
                </div>
                <div>
                    <label class="tatc-label">Batch Pelatihan &amp; Bab Silabus</label>
                    <div class="d-flex gap-2">
                        <select name="batch_code" class="tatc-select">
                            <?php foreach ($schedules as $sc): ?>
                                <option value="<?php echo s($sc->batch_code); ?>"><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="chapter_no" class="tatc-select" style="max-width:120px;">
                            <?php for ($bi = 1; $bi <= 12; $bi++): ?>
                                <option value="Bab <?php echo $bi; ?>">Bab <?php echo $bi; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="tatc-label">Format Quiz / Evaluasi *</label>
                    <select name="exam_type" class="tatc-select">
                        <option value="Quiz Pilihan Ganda (Multiple Choice)">Pilihan Ganda (Multiple Choice)</option>
                        <option value="Quiz Essay (Uraian)">Essay (Uraian)</option>
                        <option value="Quiz Campuran (Pilihan Ganda & Essay)">Campuran (Pilihan Ganda &amp; Essay)</option>
                        <option value="Practical Assessment (Hangar)">Practical Assessment (Hangar)</option>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Tanggal Pelaksanaan</label>
                    <input type="date" name="exam_date" class="tatc-input" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                </div>
                <div>
                    <label class="tatc-label">Durasi (Menit) &amp; Passing Grade</label>
                    <div class="d-flex gap-2">
                        <input type="number" name="duration_mins" class="tatc-input" value="60" min="15">
                        <input type="number" name="passing_grade" class="tatc-input" value="75" min="50" max="100">
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="tatc-btn-red">Buat Quiz &amp; Lanjut Tambah Soal / Gambar &rarr;</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- TAB 2: ASSIGNMENT / PENUGASAN (LENGKAP DENGAN SETTING DEADLINE DI ADMIN) -->
    <?php if ($active_tab === 'assignment'): ?>

    <?php if ($can_manage_exam && $viewing_assign): ?>
    <?php
    $assign_subs = $DB->get_records('amto_assignment_submissions', ['assignment_id' => $viewing_assign->id], 'timemodified DESC');
    ?>
    <div class="tatc-panel" style="border: 2px solid #6d28d9; background: #faf5ff;">
        <div class="tatc-panel-header">
            <div>
                <span class="tatc-badge tatc-badge-ongoing" style="background:#ede9fe; color:#6d28d9; margin-bottom:6px;">DAFTAR PENGUMPULAN TUGAS PESERTA</span>
                <h2 class="tatc-panel-title"><?php echo s($viewing_assign->title); ?> (<?php echo s($viewing_assign->chapter_no); ?>)</h2>
                <p class="tatc-panel-sub">Deadline: <strong><?php echo s($viewing_assign->deadline_at); ?> WIB</strong> &bull; Total Terkumpul: <strong><?php echo count($assign_subs); ?> Peserta</strong></p>
            </div>
            <a href="/amto/ujian_logbook.php?tab=assignment" class="tatc-btn-outline">Tutup Panel Pengumpulan</a>
        </div>

        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>Waktu Kumpul</th>
                        <th>Nama Peserta</th>
                        <th>Jawaban Teks &amp; File Tugas</th>
                        <th>Status Deadline</th>
                        <th>Nilai &amp; Feedback</th>
                        <th>Beri Nilai (Admin/Instruktur)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assign_subs)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:24px; color:#64748b;">Belum ada peserta yang mengumpulkan tugas pada Assignment ini.</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($assign_subs as $sub): ?>
                    <tr>
                        <td style="font-size:12px;"><?php echo date('d M Y, H:i', (int)$sub->timemodified); ?> WIB</td>
                        <td>
                            <strong><?php echo s($sub->fullname); ?></strong>
                            <div style="font-size:11.5px; color:#64748b;"><code><?php echo s($sub->username); ?></code></div>
                        </td>
                        <td>
                            <?php if (!empty($sub->answer_text)): ?>
                                <div style="font-size:13px; color:#0f172a; margin-bottom:6px;"><?php echo nl2br(s($sub->answer_text)); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($sub->file_path)): ?>
                                <a href="/amto/download.php?type=assignsub&amp;id=<?php echo (int)$sub->id; ?>" target="_blank" class="tatc-btn-outline" style="padding:4px 10px; font-size:11.5px;">
                                    Unduh File Tugas Peserta
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="tatc-badge <?php echo $sub->is_late ? 'tatc-badge-rejected' : 'tatc-badge-approved'; ?>">
                                <?php echo $sub->is_late ? 'Terlambat (Melewati Deadline)' : 'Tepat Waktu'; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($sub->score !== null): ?>
                                <strong style="font-size:16px; color:#15803d;"><?php echo (int)$sub->score; ?> / 100</strong>
                            <?php else: ?>
                                <span class="tatc-badge tatc-badge-pending">Belum Dinilai</span>
                            <?php endif; ?>
                            <div style="font-size:11.5px; color:#64748b; margin-top:3px;"><?php echo s($sub->feedback); ?></div>
                        </td>
                        <td>
                            <form method="post" action="/amto/ujian_logbook.php?tab=assignment&amp;view_assign=<?php echo (int)$viewing_assign->id; ?>" class="d-flex flex-column gap-1">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="action" value="grade_assignment">
                                <input type="hidden" name="sub_id" value="<?php echo (int)$sub->id; ?>">
                                <input type="hidden" name="assign_id" value="<?php echo (int)$viewing_assign->id; ?>">
                                <div class="d-flex gap-1">
                                    <input type="number" name="score" class="tatc-input" style="padding:4px 8px; width:75px; font-size:12px;" value="<?php echo $sub->score !== null ? (int)$sub->score : 85; ?>" min="0" max="100" required>
                                    <input type="text" name="feedback" class="tatc-input" style="padding:4px 8px; font-size:12px;" placeholder="Feedback..." value="<?php echo s((string)$sub->feedback); ?>">
                                    <button type="submit" class="tatc-btn-red" style="padding:4px 10px; font-size:11.5px; background:#6d28d9;">Simpan</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- FORM PENGUMPULAN ASSIGNMENT OLEH PESERTA -->
    <?php if ($submitting_assign): ?>
    <?php
    $my_sub = $DB->get_record('amto_assignment_submissions', ['assignment_id' => $submitting_assign->id, 'userid' => $USER->id]);
    $dl_ts = strtotime($submitting_assign->deadline_at);
    $is_expired = ($dl_ts && time() > $dl_ts);
    ?>
    <div class="tatc-panel" style="border: 2px solid #6d28d9; background: #faf5ff;">
        <div class="tatc-panel-header">
            <div>
                <span class="tatc-badge tatc-badge-ongoing" style="background:#ede9fe; color:#6d28d9; margin-bottom:6px;">LEMBAR PENGUMPULAN ASSIGNMENT &bull; <?php echo s($submitting_assign->chapter_no); ?></span>
                <h2 class="tatc-panel-title"><?php echo s($submitting_assign->title); ?></h2>
                <p class="tatc-panel-sub">
                    Batas Waktu (Deadline): <strong style="color:<?php echo $is_expired ? '#b91c1c' : '#15803d'; ?>;"><?php echo s($submitting_assign->deadline_at); ?> WIB <?php echo $is_expired ? '(Sudah Melewati Deadline)' : '(Masih Dibuka)'; ?></strong>
                </p>
            </div>
            <a href="/amto/ujian_logbook.php?tab=assignment" class="tatc-btn-outline">Tutup</a>
        </div>

        <?php if (!empty($submitting_assign->description)): ?>
            <div style="background:#ffffff; border:1px solid #ddd6fe; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-size:13.5px;">
                <strong>Instruksi Penugasan:</strong><br>
                <?php echo nl2br(s($submitting_assign->description)); ?>
                <?php if (!empty($submitting_assign->attachment_path)): ?>
                    <div class="mt-2">
                        <a href="/amto/download.php?type=assign&amp;id=<?php echo (int)$submitting_assign->id; ?>" target="_blank" class="tatc-btn-outline" style="padding:5px 12px; font-size:12px;">
                            Unduh File / Gambar Lampiran Soal
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/amto/ujian_logbook.php?tab=assignment" enctype="multipart/form-data">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="submit_assignment">
            <input type="hidden" name="assign_id" value="<?php echo (int)$submitting_assign->id; ?>">

            <div class="mb-3">
                <label class="tatc-label">Jawaban Teks / Penjelasan Tugas Anda *</label>
                <textarea name="answer_text" class="tatc-textarea" rows="4" placeholder="Tuliskan jawaban atau ringkasan laporan tugas Anda di sini..." required><?php echo s($my_sub->answer_text ?? ''); ?></textarea>
            </div>
            <div class="mb-3">
                <label class="tatc-label">Unggah File Tugas (PDF / DOCX / PPTX / ZIP / Gambar - Opsional)</label>
                <input type="file" name="submission_file" class="tatc-input" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,.png,.jpg,.jpeg,.webp">
            </div>
            <div class="d-flex justify-content-end gap-2">
                <a href="/amto/ujian_logbook.php?tab=assignment" class="tatc-btn-outline">Batal</a>
                <button type="submit" class="tatc-btn-red" style="background:#6d28d9;">Kirim &amp; Kumpulkan Tugas</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- TABEL DAFTAR ASSIGNMENT / PENUGASAN -->
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Assignment (Penugasan Kelas) &amp; Jadwal Deadline</h2>
                <p class="tatc-panel-sub">Penugasan terstruktur per Bab Silabus lengkap dengan pengaturan batas waktu pengumpulan (<em>Deadline</em>).</p>
            </div>
            <span class="tatc-badge tatc-badge-ongoing" style="background:#ede9fe; color:#6d28d9;"><?php echo count($assignments); ?> Assignment Aktif</span>
        </div>

        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>Batch &amp; Bab</th>
                        <th>Judul &amp; Instruksi Assignment</th>
                        <th>Lampiran Soal</th>
                        <th>Batas Waktu (Deadline)</th>
                        <th>Status / Pengumpulan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:32px 16px; color:#64748b;">
                            <strong>Belum ada Assignment (Penugasan) yang dibuat.</strong><br>
                            Admin / Instruktur dapat membuat Assignment lengkap dengan setting Deadline melalui form di bawah ini atau langsung dari dalam Bab Silabus Kelas.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($assignments as $asg): ?>
                    <?php
                    $dl_ts = strtotime($asg->deadline_at);
                    $is_expired = ($dl_ts && time() > $dl_ts);
                    $sub_count = $DB->count_records('amto_assignment_submissions', ['assignment_id' => $asg->id]);
                    $my_sub = $is_student ? $DB->get_record('amto_assignment_submissions', ['assignment_id' => $asg->id, 'userid' => $USER->id]) : null;
                    ?>
                    <tr>
                        <td>
                            <span class="tatc-badge tatc-badge-ongoing" style="background:#ede9fe; color:#6d28d9;"><?php echo s($asg->chapter_no); ?></span>
                            <div style="font-size:12px; color:#64748b; margin-top:4px;">Batch: <code><?php echo s($asg->batch_code); ?></code></div>
                        </td>
                        <td>
                            <div style="font-weight:700; color:#0f172a; font-size:14px;"><?php echo s($asg->title); ?></div>
                            <div style="font-size:12.5px; color:#475569; margin-top:2px; max-width:340px;"><?php echo s($asg->description); ?></div>
                        </td>
                        <td>
                            <?php if (!empty($asg->attachment_path)): ?>
                                <a href="/amto/download.php?type=assign&amp;id=<?php echo (int)$asg->id; ?>" target="_blank" class="tatc-btn-outline" style="padding:5px 10px; font-size:12px;">
                                    Unduh Soal / Gambar
                                </a>
                            <?php else: ?>
                                <span style="font-size:12px; color:#94a3b8;">Instruksi Teks</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:700; color:<?php echo $is_expired ? '#b91c1c' : '#0f172a'; ?>;">
                                <?php echo s($asg->deadline_at); ?> WIB
                            </div>
                            <span class="tatc-badge <?php echo $is_expired ? 'tatc-badge-rejected' : 'tatc-badge-approved'; ?>" style="margin-top:4px;">
                                <?php echo $is_expired ? 'Melewati Deadline' : 'Masih Dibuka'; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($is_student): ?>
                                <?php if ($my_sub): ?>
                                    <span class="tatc-badge tatc-badge-approved"><?php echo s($my_sub->status); ?></span>
                                    <?php if ($my_sub->score !== null): ?>
                                        <div style="font-size:13px; font-weight:800; color:#15803d; margin-top:4px;">Nilai: <?php echo (int)$my_sub->score; ?> / 100</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="tatc-badge tatc-badge-pending">Belum Mengumpulkan</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <strong><?php echo $sub_count; ?> Peserta</strong> Mengumpulkan
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($can_manage_exam): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <a href="/amto/ujian_logbook.php?tab=assignment&amp;view_assign=<?php echo (int)$asg->id; ?>" class="tatc-btn-red" style="padding:6px 10px; font-size:12px; background:#6d28d9;">
                                        Cek Pengumpulan (<?php echo $sub_count; ?>)
                                    </a>
                                    <a href="/amto/ujian_logbook.php?tab=assignment&amp;edit_assign=<?php echo (int)$asg->id; ?>" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#1d4ed8 !important; border-color:#bfdbfe; background:#eff6ff;">
                                        Edit &amp; Deadline
                                    </a>
                                    <form method="post" action="/amto/ujian_logbook.php?tab=assignment" style="display:inline;" onsubmit="return confirm('Hapus assignment ini?');">
                                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assign_id" value="<?php echo (int)$asg->id; ?>">
                                        <button type="submit" class="tatc-btn-outline" style="padding:6px 10px; font-size:12px; color:#b91c1c !important; border-color:#fecaca; background:#fef2f2;">
                                            Hapus
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <a href="/amto/ujian_logbook.php?tab=assignment&amp;submit_assign=<?php echo (int)$asg->id; ?>" class="tatc-btn-red" style="padding:6px 12px; font-size:12px; background:#6d28d9;">
                                    <?php echo $my_sub ? 'Perbarui Pengumpulan' : 'Kumpulkan Tugas'; ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($can_manage_exam && $editing_assign): ?>
    <div class="tatc-panel" style="border: 2px solid #6d28d9; background: #faf5ff;">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Edit Assignment &amp; Setting Deadline: <?php echo s($editing_assign->title); ?></h2>
                <p class="tatc-panel-sub">Ubah judul tugas, instruksi, lampiran soal, atau atur ulang tanggal &amp; jam batas akhir pengumpulan (<em>Deadline</em>).</p>
            </div>
            <a href="/amto/ujian_logbook.php?tab=assignment" class="tatc-btn-outline">Batal Edit</a>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=assignment" enctype="multipart/form-data">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="edit_assignment">
            <input type="hidden" name="assign_id" value="<?php echo (int)$editing_assign->id; ?>">
            <div class="tatc-form-grid mb-3">
                <div>
                    <label class="tatc-label">Judul Assignment / Tugas *</label>
                    <input type="text" name="title" class="tatc-input" value="<?php echo s($editing_assign->title); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Batch Pelatihan &amp; Bab Silabus</label>
                    <div class="d-flex gap-2">
                        <select name="batch_code" class="tatc-select">
                            <?php foreach ($schedules as $sc): ?>
                                <option value="<?php echo s($sc->batch_code); ?>" <?php echo ($editing_assign->batch_code === $sc->batch_code) ? 'selected' : ''; ?>><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="chapter_no" class="tatc-select" style="max-width:120px;">
                            <?php for ($bi = 1; $bi <= 12; $bi++): $bl = 'Bab ' . $bi; ?>
                                <option value="<?php echo $bl; ?>" <?php echo ($editing_assign->chapter_no === $bl) ? 'selected' : ''; ?>><?php echo $bl; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="tatc-label">Setting Deadline (Tanggal &amp; Jam Batas Akhir) *</label>
                    <input type="datetime-local" name="deadline_at" class="tatc-input" value="<?php echo s(str_replace(' ', 'T', substr($editing_assign->deadline_at, 0, 16))); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Ganti Lampiran File / Gambar Soal (Opsional)</label>
                    <input type="file" name="assign_file" class="tatc-input" accept=".pdf,.ppt,.pptx,.doc,.docx,.zip,.png,.jpg,.jpeg,.webp">
                </div>
                <div>
                    <label class="tatc-label">Passing Grade</label>
                    <input type="number" name="passing_grade" class="tatc-input" value="<?php echo (int)$editing_assign->passing_grade; ?>" min="50" max="100">
                </div>
            </div>
            <div class="mb-3">
                <label class="tatc-label">Instruksi / Deskripsi Penugasan *</label>
                <textarea name="description" class="tatc-textarea" rows="3" required><?php echo s((string)$editing_assign->description); ?></textarea>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <a href="/amto/ujian_logbook.php?tab=assignment" class="tatc-btn-outline">Batal</a>
                <button type="submit" class="tatc-btn-red" style="background:#6d28d9;">Simpan Perubahan &amp; Deadline</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($can_manage_exam): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Buat Assignment (Penugasan) Baru Lengkap dengan Setting Deadline</h2>
                <p class="tatc-panel-sub">Tambahkan tugas untuk peserta beserta lampiran file/gambar soal dan pengaturan batas waktu pengumpulan (<em>Deadline</em>).</p>
            </div>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=assignment" enctype="multipart/form-data">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add_assignment">
            <div class="tatc-form-grid mb-3">
                <div>
                    <label class="tatc-label">Judul Assignment / Penugasan *</label>
                    <input type="text" name="title" class="tatc-input" placeholder="Contoh: Tugas Analisis Rangkaian Avionik & Troubleshooting" required>
                </div>
                <div>
                    <label class="tatc-label">Target Kelas / Batch &amp; Bab Silabus *</label>
                    <div class="d-flex gap-2">
                        <select name="batch_code" class="tatc-select">
                            <?php foreach ($schedules as $sc): ?>
                                <option value="<?php echo s($sc->batch_code); ?>"><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="chapter_no" class="tatc-select" style="max-width:120px;">
                            <?php for ($bi = 1; $bi <= 12; $bi++): ?>
                                <option value="Bab <?php echo $bi; ?>">Bab <?php echo $bi; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="tatc-label">Setting Deadline (Tanggal &amp; Jam Batas Akhir) *</label>
                    <input type="datetime-local" name="deadline_at" class="tatc-input" value="<?php echo date('Y-m-d\T23:59', strtotime('+7 days')); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">Lampirkan File / Gambar Soal Tugas (Opsional)</label>
                    <input type="file" name="assign_file" class="tatc-input" accept=".pdf,.ppt,.pptx,.doc,.docx,.zip,.png,.jpg,.jpeg,.webp">
                </div>
                <div>
                    <label class="tatc-label">Passing Grade Assignment</label>
                    <input type="number" name="passing_grade" class="tatc-input" value="75" min="50" max="100">
                </div>
            </div>
            <div class="mb-3">
                <label class="tatc-label">Instruksi / Deskripsi Penugasan *</label>
                <textarea name="description" class="tatc-textarea" rows="3" placeholder="Tuliskan detail instruksi pengerjaan tugas dan format pengumpulan..." required></textarea>
            </div>
            <div>
                <button type="submit" class="tatc-btn-red" style="background:#6d28d9;">+ Simpan &amp; Terbitkan Assignment</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- TAB 3: PRACTICAL LOGBOOK HANGAR -->
    <?php if ($active_tab === 'logbook'): ?>
    <?php if (!$is_readonly_audit): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Input Entri Practical Logbook Hangar Baru</h2>
                <p class="tatc-panel-sub">Catat aktivitas praktik perawatan pesawat udara, referensi ATA Chapter / AMM, lokasi hangar, dan jam praktik.</p>
            </div>
            <span class="tatc-badge tatc-badge-ongoing">Wajib Sign-Off Instruktur</span>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=logbook">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add_logbook">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Batch Pelatihan *</label>
                    <select name="batch_code" class="tatc-select">
                        <?php foreach ($schedules as $sc): ?>
                            <option value="<?php echo s($sc->batch_code); ?>"><?php echo s($sc->batch_code . ' – ' . $sc->program_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Tanggal Praktik *</label>
                    <input type="date" name="practice_date" class="tatc-input" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div>
                    <label class="tatc-label">ATA Chapter &amp; Sistem Pesawat *</label>
                    <input type="text" name="ata_chapter" class="tatc-input" placeholder="Contoh: ATA 24 – Electrical Power / ATA 34 – Navigation" required>
                </div>
                <div>
                    <label class="tatc-label">Judul Pekerjaan / Referensi AMM *</label>
                    <input type="text" name="task_title" class="tatc-input" placeholder="Contoh: Generator & Bus Tie Functional Check per AMM 24-21-00" required>
                </div>
                <div>
                    <label class="tatc-label">Lokasi Hangar / Workshop</label>
                    <input type="text" name="hangar_location" class="tatc-input" value="Hangar B-01 TATC">
                </div>
                <div>
                    <label class="tatc-label">Durasi Jam Praktik &amp; Tools</label>
                    <div class="d-flex gap-2">
                        <input type="text" name="duration_hours" class="tatc-input" value="4.0 Jam" style="max-width:120px;">
                        <input type="text" name="tools_used" class="tatc-input" placeholder="Alat ukur / Special Tools yang digunakan">
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <label class="tatc-label">Deskripsi Pelaksanaan Pekerjaan &amp; Observasi Keselamatan</label>
                <textarea name="description" class="tatc-textarea" rows="2" placeholder="Jelaskan langkah pemeriksaan, hasil pengukuran, dan penerapan prosedur keselamatan FOD/EWIS..."></textarea>
            </div>
            <div class="mt-3">
                <button type="submit" class="tatc-btn-red">Simpan ke Practical Logbook Saya</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Daftar Rekaman Practical Logbook Hangar</h2>
                <p class="tatc-panel-sub">Riwayat jam pengalaman praktik perawatan pesawat udara beserta status verifikasi (Sign-Off) Instruktur.</p>
            </div>
            <span class="tatc-badge tatc-badge-approved"><?php echo $verified_logs; ?> Verified / <?php echo $pending_logs; ?> Pending</span>
        </div>
        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>No. Logbook &amp; Tanggal</th>
                        <th>Peserta &amp; Batch</th>
                        <th>ATA Chapter &amp; Task AMM</th>
                        <th>Lokasi &amp; Jam</th>
                        <th>Catatan &amp; Verifikator</th>
                        <th>Status</th>
                        <?php if ($can_verify_logbook): ?><th>Verifikasi Instruktur</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logbooks)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:32px 16px; color:#64748b;">
                            <strong>Belum ada rekaman Practical Logbook Hangar.</strong><br>
                            Peserta dapat menginput catatan praktik hangar pada form di atas untuk kemudian diverifikasi (Sign-Off) oleh Instruktur / Assessor.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($logbooks as $lb): ?>
                    <tr>
                        <td>
                            <strong style="color:#b91c1c;"><?php echo s($lb->log_no); ?></strong>
                            <div style="font-size:12px; color:#64748b;"><?php echo s($lb->practice_date); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:700;"><?php echo s($lb->fullname); ?></div>
                            <code><?php echo s($lb->batch_code); ?></code>
                        </td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;"><?php echo s($lb->ata_chapter); ?></div>
                            <div style="font-size:12.5px; font-weight:600; color:#334155;"><?php echo s($lb->task_title); ?></div>
                            <div style="font-size:11.5px; color:#64748b; margin-top:2px;"><?php echo s($lb->description); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:12.5px;"><?php echo s($lb->hangar_location); ?></div>
                            <div style="font-size:12px; color:#1d4ed8; font-weight:700;"><?php echo s($lb->duration_hours); ?></div>
                        </td>
                        <td>
                            <div style="font-size:12px; color:#334155;"><?php echo s($lb->instructor_notes); ?></div>
                            <?php if (!empty($lb->verified_by)): ?>
                                <div style="font-size:11.5px; color:#15803d; font-weight:700; margin-top:2px;">Sign-Off: <?php echo s($lb->verified_by); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="tatc-badge <?php echo (stripos($lb->status, 'Verified') !== false) ? 'tatc-badge-approved' : ((stripos($lb->status, 'Revisi') !== false) ? 'tatc-badge-rejected' : 'tatc-badge-pending'); ?>">
                                <?php echo s($lb->status); ?>
                            </span>
                        </td>
                        <?php if ($can_verify_logbook): ?>
                        <td>
                            <form method="post" action="/amto/ujian_logbook.php?tab=logbook" class="d-flex flex-column gap-1">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="action" value="verify_logbook">
                                <input type="hidden" name="log_id" value="<?php echo (int)$lb->id; ?>">
                                <input type="text" name="instructor_notes" class="tatc-input" style="padding:4px 8px; font-size:11.5px;" placeholder="Catatan sign-off...">
                                <div class="d-flex gap-1">
                                    <button type="submit" name="decision" value="approve" class="tatc-btn-red" style="padding:4px 8px; font-size:11px; background:#15803d;">Sign-Off</button>
                                    <button type="submit" name="decision" value="revise" class="tatc-btn-outline" style="padding:4px 8px; font-size:11px;">Revisi</button>
                                </div>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB 4: HASIL ASSESSMENT & TRANSKRIP NILAI (DENGAN PREVIEW JAWABAN ESSAY / PILIHAN GANDA) -->
    <?php if ($active_tab === 'hasil'): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Transkrip Hasil Quiz / Ujian &amp; Evaluasi Kompetensi (Assessment Results)</h2>
                <p class="tatc-panel-sub">Rekapitulasi nilai Quiz Pilihan Ganda, jawaban Essay peserta, dan evaluasi praktik hangar berdasarkan ambang batas kelulusan CASR Part 147 (&ge; 75).</p>
            </div>
            <a href="/amto/sertifikat.php" class="tatc-btn-red" style="padding:7px 14px; font-size:12.5px;">Lihat Kelayakan E-Sertifikat</a>
        </div>
        <div class="tatc-table-wrap">
            <table class="tatc-table">
                <thead>
                    <tr>
                        <th>Tanggal Evaluasi</th>
                        <th>Kode &amp; Modul Quiz/Ujian</th>
                        <th>Nama Peserta</th>
                        <th>Skor Nilai</th>
                        <th>Status Kompetensi</th>
                        <th>Detail Jawaban (Pilihan Ganda / Essay) &amp; Catatan</th>
                        <?php if ($can_manage_exam): ?><th>Update Nilai (Admin/Assessor)</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($exam_results)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:32px 16px; color:#64748b;">
                            <strong>Belum ada hasil quiz / ujian yang tercatat.</strong><br>
                            Nilai akan otomatis muncul setelah Peserta mengerjakan Quiz atau setelah Examiner / Assessor menginput nilai evaluasi.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($exam_results as $er): ?>
                    <?php
                    $parsed_answers = !empty($er->answers_json) ? json_decode($er->answers_json, true) : null;
                    ?>
                    <tr>
                        <td style="font-size:12.5px; color:#475569; white-space:nowrap;"><?php echo date('d M Y, H:i', (int)$er->timecreated); ?></td>
                        <td>
                            <strong style="color:#b91c1c;"><?php echo s($er->exam_code); ?></strong>
                            <div style="font-weight:700; color:#0f172a;"><?php echo s($er->exam_title); ?></div>
                            <div style="font-size:11.5px; color:#64748b;">Passing Grade: <?php echo (int)$er->passing_grade; ?></div>
                        </td>
                        <td>
                            <strong><?php echo s($er->fullname); ?></strong>
                            <div style="font-size:11.5px; color:#64748b;"><code><?php echo s($er->username); ?></code></div>
                        </td>
                        <td>
                            <span style="font-size:18px; font-weight:800; color:<?php echo ($er->score >= $er->passing_grade) ? '#15803d' : '#b91c1c'; ?>;">
                                <?php echo (int)$er->score; ?>
                            </span>
                            <span style="font-size:12px; color:#64748b;">/ 100</span>
                        </td>
                        <td>
                            <span class="tatc-badge <?php echo ($er->score >= $er->passing_grade) ? 'tatc-badge-approved' : 'tatc-badge-rejected'; ?>">
                                <?php echo s($er->status); ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:12px;"><?php echo s($er->assessed_by); ?></div>
                            <div style="font-size:12px; color:#64748b; margin-bottom:6px;"><?php echo s($er->notes); ?></div>
                            <?php if (is_array($parsed_answers) && !empty($parsed_answers)): ?>
                                <details style="font-size:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:6px 10px;">
                                    <summary style="cursor:pointer; font-weight:700; color:#1d4ed8;">Lihat Rincian Jawaban Peserta (<?php echo count($parsed_answers); ?> Soal)</summary>
                                    <div style="margin-top:8px; display:flex; flex-direction:column; gap:6px;">
                                        <?php foreach ($parsed_answers as $pa): ?>
                                            <div style="border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                                                <div><strong>#<?php echo s($pa['no'] ?? ''); ?> [<?php echo s($pa['type'] ?? ''); ?>]:</strong> <?php echo s($pa['question'] ?? ''); ?></div>
                                                <div style="color:#0f172a;">Jawaban Peserta: <strong><?php echo s($pa['answer'] ?? ''); ?></strong></div>
                                                <div style="color:#64748b; font-size:11px;"><?php echo s($pa['expected'] ?? ''); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                        <?php if ($can_manage_exam): ?>
                        <td>
                            <form method="post" action="/amto/ujian_logbook.php?tab=hasil" class="d-flex flex-column gap-1">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="action" value="update_result_score">
                                <input type="hidden" name="result_id" value="<?php echo (int)$er->id; ?>">
                                <div class="d-flex gap-1">
                                    <input type="number" name="score" class="tatc-input" style="padding:4px 8px; width:72px; font-size:12px;" value="<?php echo (int)$er->score; ?>" min="0" max="100" required>
                                    <button type="submit" class="tatc-btn-red" style="padding:4px 10px; font-size:11.5px;">Update</button>
                                </div>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($can_manage_exam): ?>
    <div class="tatc-panel">
        <div class="tatc-panel-header">
            <div>
                <h2 class="tatc-panel-title">Input Nilai Assessment Manual (Khusus Examiner / Assessor &amp; Training Manager)</h2>
                <p class="tatc-panel-sub">Masukkan hasil penilaian ujian tertulis atau evaluasi praktik hangar peserta ke transkrip resmi.</p>
            </div>
        </div>
        <form method="post" action="/amto/ujian_logbook.php?tab=hasil">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="input_grade">
            <div class="tatc-form-grid">
                <div>
                    <label class="tatc-label">Pilih Modul Quiz / Ujian *</label>
                    <select name="exam_id" class="tatc-select">
                        <?php foreach ($exams as $ex): ?>
                            <option value="<?php echo (int)$ex->id; ?>"><?php echo s($ex->exam_code . ' – ' . $ex->title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tatc-label">Username Peserta *</label>
                    <input type="text" name="student_username" class="tatc-input" value="peserta" required>
                </div>
                <div>
                    <label class="tatc-label">Skor Nilai (0 - 100) *</label>
                    <input type="number" name="score" class="tatc-input" value="85" min="0" max="100" required>
                </div>
                <div>
                    <label class="tatc-label">Catatan Evaluasi Assessor</label>
                    <input type="text" name="notes" class="tatc-input" placeholder="Contoh: Lulus ujian teori & praktik sesuai standar CASR 147">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="tatc-btn-red">Simpan Nilai Assessment</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();
