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
// Additional synthetic identities for work-chat HTTP acceptance.
if (!class_exists('\local_ustar\chat_groups')) { return; }
require_once($CFG->dirroot . '/user/lib.php');
foreach (['chatone', 'chattwo', 'chatoutsider'] as $username) {
    if (!$DB->record_exists('user', ['username' => $username])) {
        $id = user_create_user((object)['username' => $username, 'auth' => 'manual',
            'password' => 'Fixture-Only-Password1!', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
            'firstname' => 'Chatfixture', 'lastname' => $username, 'email' => $username . '@example.invalid']);
        // Authenticated-user archetype already grants local/ustar:use and sendmessage.
        unset_user_preference('auth_forcepasswordchange', $id);
    }
}
set_config('messaging', 1);
set_config('messagingallusers', 1);
echo 'WORKCHAT_HTTP_FIXTURE=READY' . PHP_EOL;
