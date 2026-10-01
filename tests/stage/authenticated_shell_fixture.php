<?php
// Synthetic isolated site only. No production credentials or data are used.
if (getenv('USTAR_STAGE_PREFIX') !== 'stage_') { throw new RuntimeException('ISOLATED_PREFIX_REQUIRED'); }
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
set_config('theme', 'ustar');
set_config('defaulthomepage', HOMEPAGE_SITE);
set_config('guestloginbutton', 1);
set_config('debug', DEBUG_DEVELOPER);
set_config('debugdisplay', 1);
// Mark the synthetic install as fully configured to exercise the product pages.
$admin = get_admin();
$admin->firstname = 'Synthetic';
$admin->lastname = 'Administrator';
$admin->email = 'stage@example.invalid';
$DB->update_record('user', $admin);
unset_user_preference('auth_forcepasswordchange', $admin->id);
echo "AUTHENTICATED_SHELL_FIXTURE=READY\n";
