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

/**
 * [SEC-FIX-04] Return granular 8-Role RBAC code (R01-R08):
 * 'student' (R01), 'instructor' (R02), 'assessor' (R03), 'trainingmanager' (R04),
 * 'certofficer' (R05), 'qa' (R06), 'adminit' (R07), 'management' (R08), or 'admin'.
 */
function amto_get_granular_rbac_role(): string {
    global $USER, $DB;
    if (!empty($USER->access['rsw']) && is_array($USER->access['rsw'])) {
        $switchedroleid = end($USER->access['rsw']);
        if ($switchedroleid && isset($DB)) {
            $role = $DB->get_record('role', ['id' => $switchedroleid], 'shortname');
            if ($role) {
                $map = [
                    'student' => 'student',
                    'guest' => 'student',
                    'user' => 'student',
                    'frontpage' => 'student',
                    'r01_trainee' => 'student',
                    'teacher' => 'instructor',
                    'editingteacher' => 'instructor',
                    'r02_instructor' => 'instructor',
                    'r03_assessor' => 'assessor',
                    'assessor' => 'assessor',
                    'coursecreator' => 'trainingmanager',
                    'r04_trainingmanager' => 'trainingmanager',
                    'trainingmanager' => 'trainingmanager',
                    'r05_certofficer' => 'certofficer',
                    'certofficer' => 'certofficer',
                    'r06_qa' => 'qa',
                    'qa' => 'qa',
                    'r07_adminit' => 'adminit',
                    'adminit' => 'adminit',
                    'r08_management' => 'management',
                    'management' => 'management',
                    'manager' => 'admin',
                ];
                if (isset($map[$role->shortname])) {
                    return $map[$role->shortname];
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
        if (array_intersect($roles, ['r07_adminit', 'adminit'])) {
            return 'adminit';
        }
        if (array_intersect($roles, ['r04_trainingmanager', 'trainingmanager', 'manager', 'coursecreator'])) {
            return 'trainingmanager';
        }
        if (array_intersect($roles, ['r05_certofficer', 'certofficer'])) {
            return 'certofficer';
        }
        if (array_intersect($roles, ['r06_qa', 'qa'])) {
            return 'qa';
        }
        if (array_intersect($roles, ['r08_management', 'management'])) {
            return 'management';
        }
        if (array_intersect($roles, ['r03_assessor', 'assessor'])) {
            return 'assessor';
        }
        if (array_intersect($roles, ['r02_instructor', 'editingteacher', 'teacher'])) {
            return 'instructor';
        }
    }
    return 'student';
}

function amto_get_effective_role(): string {
    $granular = amto_get_granular_rbac_role();
    if (in_array($granular, ['admin', 'adminit', 'trainingmanager', 'certofficer', 'qa', 'management'], true)) {
        return 'admin';
    }
    if (in_array($granular, ['instructor', 'assessor'], true)) {
        return 'instructor';
    }
    return 'student';
}

require_once(__DIR__ . '/lib/setup.php');
