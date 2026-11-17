<?php
define('CLI_SCRIPT', true);
require('C:/moodle/server/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

global $DB;

echo "1. Removing sample courses so Moodle is clean/empty...\n";
$courses = $DB->get_records_select('course', 'id > 1');
foreach ($courses as $course) {
    delete_course($course, false);
    echo "   - Deleted course: {$course->shortname}\n";
}
fix_course_sortorder();

echo "2. Removing custom categories (keeping default Miscellaneous/General category)...\n";
$cats = $DB->get_records_select('course_categories', "idnumber LIKE 'AMTO-CAT-%'");
foreach ($cats as $cat) {
    $coursecat = \core_course_category::get($cat->id, IGNORE_MISSING);
    if ($coursecat && $coursecat->can_delete_full()) {
        $coursecat->delete_full(false);
        echo "   - Deleted category: {$cat->name}\n";
    }
}

echo "3. Removing Hero Banner HTML block from Dashboard (/my/) & Site Home...\n";
$DB->delete_records('block_instances', ['blockname' => 'html', 'pagetypepattern' => 'my-index']);
$DB->set_field('course_format_options', 'value', '0', ['courseid' => SITEID, 'format' => 'site', 'name' => 'numsections']);
$DB->set_field('course_sections', 'summary', '', ['course' => SITEID, 'section' => 1]);
set_config('custommenuitems', '');
set_config('defaulthomepage', 1); // Default to Dashboard (/my/)

echo "4. Keeping clean Aviation Navy Theme SCSS (Navbar, Cards, Buttons, Login)...\n";
$cleanscss = <<<'SCSS'
// Clean AMTO Aviation Theme SCSS for Moodle 5.3 Boost
.navbar.fixed-top {
    background: linear-gradient(90deg, #061326 0%, #0a1f3d 100%) !important;
    border-bottom: 2px solid #0ea5e9 !important;
    box-shadow: 0 4px 16px rgba(6, 19, 38, 0.2);
}
.navbar.fixed-top .navbar-brand {
    color: #ffffff !important;
    font-weight: 800 !important;
    letter-spacing: -0.3px;
}
.navbar.fixed-top .primary-navigation .navigation .nav-link,
.navbar.fixed-top .nav-link,
.navbar.fixed-top .moremenu .nav-link,
.navbar.fixed-top .usermenu .login,
.navbar.fixed-top .usermenu .login a,
.navbar.fixed-top .langmenu .dropdown-toggle,
.navbar.fixed-top .edittoggle .form-check-label,
.navbar.fixed-top .popover-region-toggle i.icon,
.navbar.fixed-top .usermenu .dropdown-toggle {
    color: #e2e8f0 !important;
    font-weight: 600 !important;
}
.navbar.fixed-top .primary-navigation .navigation .nav-link:hover,
.navbar.fixed-top .nav-link:hover {
    color: #38bdf8 !important;
    background: rgba(255, 255, 255, 0.08) !important;
    border-radius: 8px;
}
.navbar.fixed-top .primary-navigation .navigation .nav-link.active,
.navbar.fixed-top .nav-link.active {
    color: #ffffff !important;
    background: #0284c7 !important;
    border-radius: 8px;
    border-bottom: none !important;
}
.navbar.fixed-top .dropdown-menu {
    background: #ffffff !important;
}
.navbar.fixed-top .dropdown-menu .dropdown-item {
    color: #0f172a !important;
}
#page.drawers {
    background-color: #f8fafc !important;
}
.card, .coursebox {
    border-radius: 14px !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04) !important;
}
.pagelayout-login #page {
    background: linear-gradient(135deg, #061326 0%, #0f2d59 60%, #0284c7 100%) !important;
}
.login-container {
    border-radius: 16px !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35) !important;
}
SCSS;

set_config('scss', $cleanscss, 'theme_boost');

echo "5. Purging Moodle Caches...\n";
purge_all_caches();
echo "CLEANUP COMPLETE!\n";
