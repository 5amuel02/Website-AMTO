<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'root';
$CFG->dbpass    = '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => 3307,
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

if (!empty($_SERVER['HTTP_HOST'])) {
    $CFG->wwwroot = 'http://' . $_SERVER['HTTP_HOST'];
} else {
    $CFG->wwwroot = 'http://localhost:8080';
}

$CFG->dataroot  = 'C:\\moodle\\server/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

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
