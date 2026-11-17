<?php
define('CLI_SCRIPT', true);
require('C:/moodle/server/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

$scss = get_config('theme_boost', 'scss');

$mds_pill_rules = <<<'SCSS'

// Exact Moodle 5.3 .mds-nav-pill styling: White text when not hovered, Light Pill + Dark Text on hover/selected
.navbar.fixed-top .primary-navigation .mds-nav-pill,
.navbar.fixed-top .mds-nav-pill,
.navbar.fixed-top .mds-nav-pill:visited,
.navbar.fixed-top .editmode-switch-form .mds-switch-label,
.navbar.fixed-top #usernavigation .nav-link,
.navbar.fixed-top #usernavigation .dropdown-toggle,
.navbar.fixed-top #usernavigation .usertext,
.navbar.fixed-top #usernavigation i.icon {
    color: #ffffff !important;
}

.navbar.fixed-top .primary-navigation .mds-nav-pill:hover,
.navbar.fixed-top .primary-navigation .mds-nav-pill:focus,
.navbar.fixed-top .primary-navigation .mds-nav-pill[aria-expanded="true"],
.navbar.fixed-top .primary-navigation .mds-nav-pill.mds-nav-pill--selected,
.navbar.fixed-top .primary-navigation .mds-nav-pill.mds-nav-pill--selected:visited {
    background-color: #e2e8f0 !important;
    color: #1e293b !important;
}

.navbar.fixed-top .primary-navigation .mds-nav-pill:hover *,
.navbar.fixed-top .primary-navigation .mds-nav-pill.mds-nav-pill--selected * {
    color: #1e293b !important;
}
SCSS;

set_config('scss', $scss . "\n" . $mds_pill_rules, 'theme_boost');
purge_all_caches();
echo "Updated .mds-nav-pill white text & hover pill rules and purged caches!\n";
