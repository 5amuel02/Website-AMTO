<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = getenv('AMTO_DB_TYPE') ?: 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('AMTO_DB_HOST') ?: 'localhost';
$CFG->dbname    = getenv('AMTO_DB_NAME') ?: 'moodle';
$CFG->dbuser    = getenv('AMTO_DB_USER') ?: 'root';
$CFG->dbpass    = getenv('AMTO_DB_PASS') !== false ? getenv('AMTO_DB_PASS') : '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => (int)(getenv('AMTO_DB_PORT') ?: 3307),
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

// [SEC-FIX-07] APP-01, APP-04 & DP-03: Validate HTTP_HOST to prevent Host Header Injection & support HTTPS
$amto_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
if (!empty($_SERVER['HTTP_HOST']) && preg_match('/^[a-zA-Z0-9.\-]+(:[0-9]{1,5})?$/', $_SERVER['HTTP_HOST'])) {
    $CFG->wwwroot = $amto_scheme . $_SERVER['HTTP_HOST'];
} else {
    $CFG->wwwroot = 'http://localhost:8080';
}

$CFG->dataroot  = getenv('AMTO_DATAROOT') ?: 'C:\\moodle\\server/moodledata';
$CFG->admin     = 'admin';

// [SEC-FIX-07] Hardened directory permissions (0750 instead of world-writable 0777) & session cookie security
$CFG->directorypermissions = 0750;
$CFG->cookiehttponly = 1;
$CFG->cookiesecure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0;

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
}

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
                    'teacher' => 'assessor',
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
                    'manager' => 'trainingmanager',
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
        if (array_intersect($roles, ['r03_assessor', 'assessor', 'teacher'])) {
            return 'assessor';
        }
        if (array_intersect($roles, ['r02_instructor', 'editingteacher'])) {
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
