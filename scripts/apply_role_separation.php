<?php
// Apply Role-Aware Navbar, Dashboard Zero-State, and Edit Mode Switch for Student vs Instructor vs Admin

// 1. Update C:/moodle/server/moodle/config.php with amto_get_effective_role()
$configpath = 'C:/moodle/server/moodle/config.php';
$configcontent = file_get_contents($configpath);
if (strpos($configcontent, 'function amto_get_effective_role') === false) {
    $helper = <<<'PHP'

function amto_get_effective_role(): string {
    global $USER, $DB;
    if (!empty($USER->access['rsw']) && is_array($USER->access['rsw'])) {
        $switchedroleid = end($USER->access['rsw']);
        if ($switchedroleid && isset($DB)) {
            $role = $DB->get_record('role', ['id' => $switchedroleid], 'shortname');
            if ($role) {
                if (in_array($role->shortname, ['student', 'guest', 'user', 'frontpage'])) {
                    return 'student';
                }
                if (in_array($role->shortname, ['teacher', 'editingteacher', 'coursecreator'])) {
                    return 'instructor';
                }
            }
        }
    }
    if (function_exists('is_siteadmin') && is_siteadmin()) {
        return 'admin';
    }
    if (!empty($USER->id) && isset($DB)) {
        $sql = "SELECT r.shortname
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = ?";
        $roles = $DB->get_fieldset_sql($sql, [$USER->id]);
        if (array_intersect($roles, ['manager', 'coursecreator'])) {
            return 'admin';
        }
        if (array_intersect($roles, ['editingteacher', 'teacher'])) {
            return 'instructor';
        }
    }
    return 'student';
}

require_once(__DIR__ . '/lib/setup.php');
PHP;
    $configcontent = str_replace("require_once(__DIR__ . '/lib/setup.php');", $helper, $configcontent);
    file_put_contents($configpath, $configcontent);
    echo "1. Added amto_get_effective_role() to config.php\n";
}

// 2. Update views/primary.php so Site Administration is hidden when viewing as Student or Instructor
$viewsprimarypath = 'C:/moodle/server/moodle/public/lib/classes/navigation/views/primary.php';
$vp = file_get_contents($viewsprimarypath);
$target_vp = 'if ($showsiteadminnode && $node = $this->get_site_admin_node()) {';
$replace_vp = 'if ($showsiteadminnode && (!function_exists(\'amto_get_effective_role\') || amto_get_effective_role() === \'admin\') && $node = $this->get_site_admin_node()) {';
if (strpos($vp, 'amto_get_effective_role') === false) {
    $vp = str_replace($target_vp, $replace_vp, $vp);
    file_put_contents($viewsprimarypath, $vp);
    echo "2. Updated views/primary.php to hide Site Administration for Student/Instructor\n";
}

// 3. Update output/primary.php so get_custom_menu() returns distinct menus for Student, Instructor, and Admin
$outprimarypath = 'C:/moodle/server/moodle/public/lib/classes/navigation/output/primary.php';
$op = file_get_contents($outprimarypath);
$old_method_start = '    protected function get_custom_menu(renderer_base $output): array {';
$old_method_end = "        return \$nodes;\n    }";

$new_method = <<<'PHP'
    protected function get_custom_menu(renderer_base $output): array {
        global $CFG;

        $role = function_exists('amto_get_effective_role') ? amto_get_effective_role() : 'admin';

        if ($role === 'student') {
            $custommenuitems = "Beranda|/?redirect=0\n"
                . "Kelas & Materi Saya|/my/courses.php\n"
                . "-Kelas Pelatihan Saya|/my/courses.php\n"
                . "-Katalog Materi LMS|/course/index.php\n"
                . "Jadwal & Presensi|/calendar/view.php?view=month\n"
                . "-Kalender Jadwal Pelatihan|/calendar/view.php?view=month\n"
                . "Ujian & Logbook|/grade/report/overview/index.php\n"
                . "-Daftar Ujian Aktif|/my/courses.php\n"
                . "-Practical Logbook Saya|/grade/report/overview/index.php\n"
                . "-Hasil Assessment Saya|/grade/report/overview/index.php\n"
                . "Sertifikat Saya|/badges/mybadges.php\n"
                . "-Status Sertifikasi Saya|/badges/mybadges.php\n"
                . "-Profil Peserta|/user/profile.php";
        } else if ($role === 'instructor') {
            $custommenuitems = "Beranda|/?redirect=0\n"
                . "Kelas Penugasan|/my/courses.php\n"
                . "-Daftar Kelas Mengajar|/my/courses.php\n"
                . "-Kalender Jadwal Mengajar|/calendar/view.php?view=month\n"
                . "Materi & Presensi|/course/index.php\n"
                . "-Kelola Materi Kelas (LMS)|/course/index.php\n"
                . "-Input & Koreksi Presensi|/course/index.php\n"
                . "Ujian & Assessment|/question/edit.php?courseid=1\n"
                . "-Bank Soal & Ujian|/question/edit.php?courseid=1\n"
                . "-Input Nilai Assessment|/grade/report/overview/index.php\n"
                . "Verifikasi Logbook|/grade/report/overview/index.php\n"
                . "-Antrean Practical Logbook Peserta|/grade/report/overview/index.php\n"
                . "-Validasi Bukti Kompetensi|/admin/tool/lp/competencyframeworks.php?pagecontextid=1";
        } else {
            $custommenuitems = $CFG->custommenuitems ?? '';
        }

        if (empty($custommenuitems)) {
            return [];
        }

        if (!empty($CFG->navfilter && !empty($CFG->stringfilters))) {
            $filtermanager = \filter_manager::instance();
            $custommenuitems = $filtermanager->filter_string($custommenuitems, \context_system::instance());
        }

        $currentlang = current_language();
        $custommenunodes = custom_menu::convert_text_to_menu_nodes($custommenuitems, $currentlang);
        $nodes = [];
        foreach ($custommenunodes as $node) {
            $nodes[] = $node->export_for_template($output);
        }

        return $nodes;
    }
PHP;

$op = preg_replace('/    protected function get_custom_menu\(renderer_base \$output\): array \{.*?        return \$nodes;\r?\n    \}/s', $new_method, $op);
file_put_contents($outprimarypath, $op);
echo "3. Updated output/primary.php with role-based menus (Student vs Instructor vs Admin)\n";

// 4. Update blocks/myoverview/classes/output/main.php so Student & Instructor have distinct Dashboard Course Overview states
$myoverviewpath = 'C:/moodle/server/moodle/public/blocks/myoverview/classes/output/main.php';
$mo = file_get_contents($myoverviewpath);
$target_mo = '    public function export_for_zero_state_template(renderer_base $output) {
        global $CFG, $DB;

        $nocoursesimg = $output->image_url(\'courses\', \'block_myoverview\');';

$replace_mo = <<<'PHP'
    public function export_for_zero_state_template(renderer_base $output) {
        global $CFG, $DB;

        $nocoursesimg = $output->image_url('courses', 'block_myoverview');

        if (function_exists('amto_get_effective_role')) {
            $effrole = amto_get_effective_role();
            if ($effrole === 'student') {
                $jadwalbtn = new \single_button(
                    new \moodle_url('/calendar/view.php', ['view' => 'month']),
                    'Lihat Jadwal Pelatihan',
                    'get',
                    \single_button::BUTTON_PRIMARY
                );
                return [
                    'nocoursesimg' => $nocoursesimg->out(),
                    'title' => 'Belum ada kelas pelatihan yang diikuti',
                    'intro' => 'Anda belum terdaftar pada kelas pelatihan aktif. Silakan cek jadwal pelatihan atau hubungi Training Manager AMTO untuk penugasan kelas Anda.',
                    'buttons' => [$jadwalbtn->export_for_template($output)],
                ];
            } else if ($effrole === 'instructor') {
                $kelasbtn = new \single_button(
                    new \moodle_url('/course/index.php'),
                    'Lihat Daftar Kelas Penugasan',
                    'get',
                    \single_button::BUTTON_PRIMARY
                );
                return [
                    'nocoursesimg' => $nocoursesimg->out(),
                    'title' => 'Belum ada kelas penugasan mengajar',
                    'intro' => 'Anda belum memiliki kelas mengajar aktif. Setelah ditugaskan oleh Training Manager, daftar kelas, materi, presensi, dan verifikasi logbook peserta akan tampil di sini.',
                    'buttons' => [$kelasbtn->export_for_template($output)],
                ];
            }
        }
PHP;

if (strpos($mo, 'Belum ada kelas pelatihan yang diikuti') === false) {
    $mo = str_replace(
        ["\r\n"],
        ["\n"],
        $mo
    );
    $target_mo_norm = str_replace(["\r\n"], ["\n"], $target_mo);
    $mo = str_replace($target_mo_norm, $replace_mo, $mo);
    file_put_contents($myoverviewpath, $mo);
    echo "4. Updated block_myoverview zero-state for Student vs Instructor vs Admin\n";
}

// 5. Hide Edit Mode switch in theme_boost core_renderer when role is Student
$rendererpath = 'C:/moodle/server/moodle/public/theme/boost/classes/output/core_renderer.php';
$rc = file_get_contents($rendererpath);
if (strpos($rc, 'function edit_switch') === false) {
    $editswitch_override = <<<'PHP'

    public function edit_switch() {
        if (function_exists('amto_get_effective_role') && amto_get_effective_role() === 'student') {
            return '';
        }
        return parent::edit_switch();
    }
}
PHP;
    $rc = preg_replace('/\}\s*$/', $editswitch_override, $rc);
    file_put_contents($rendererpath, $rc);
    echo "5. Updated theme_boost core_renderer to hide Edit mode switch for Student\n";
}

define('CLI_SCRIPT', true);
require('C:/moodle/server/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
purge_all_caches();
echo "ALL ROLE-BASED UI UPDATES COMPLETED & CACHES PURGED!\n";
