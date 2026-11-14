<?php
// Disposable Moodle stage only.
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
if (getenv('USTAR_STAGE_OPERATOR_HTTP') !== '1' || getenv('USTAR_STAGE_PREFIX') !== 'stage_') {
    throw new RuntimeException('ISOLATED_STAGE_REQUIRED');
}
require_once($CFG->dirroot . '/user/lib.php');
use local_ustar\connect\{bot_confirmation, bot_store, identity, identity_service};

if (($argv[1] ?? '') === 'status') {
    echo $DB->count_records_select('local_ustar_conn_challenges', 'issuedby > 0');
    exit;
}
$operator = null;
foreach (['operatorhttp', 'operatorinvitee'] as $name) {
    $userid = user_create_user((object)['username' => $name, 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Synthetic', 'lastname' => $name,
        'email' => $name . '@example.invalid', 'password' => 'Fixture-Only-Password1!']);
    unset_user_preference('auth_forcepasswordchange', $userid);
    if ($name === 'operatorhttp') {
        $operator = $userid;
    }
}
$roleid = $DB->get_field('role', 'id', ['shortname' => 'ustar_superadmin'], MUST_EXIST);
role_assign($roleid, $operator, context_system::instance()->id);
\core\session\manager::set_user(get_admin());
set_config('sitepolicyhandler', 'tool_policy');
$form = \tool_policy\api::form_policydoc_data(new \tool_policy\policy_version(0));
$form->name = 'Synthetic invitation policy';
$form->content_editor['text'] = '<p>Terms for the fixture.</p>';
$form->content_editor['format'] = FORMAT_HTML;
$version = \tool_policy\api::form_policydoc_add($form)->get('id');
\tool_policy\api::make_current($version);
\core\session\manager::set_user(\core_user::get_user($operator));
\tool_policy\api::accept_policies($version);
$identity = new identity('telegram', '123456', '998877');
$code = identity_service::issue('telegram', '123456')['code'];
identity_service::claim_verified($code, $identity, hash('sha256', 'operator-http'));
$link = identity_service::confirm($code);
$dialog = bot_store::dialog($identity, $operator, $DB->get_record('local_ustar_conn_links', ['id' => $link['id']], '*', MUST_EXIST));
$view = bot_confirmation::prepare($dialog, 'execute.invitation.issue', ['userid' => $userid, 'subject' => '998878'],
    'Приглашение для synthetic operatorinvitee');
foreach ($view['buttons'] as $button) {
    if (isset($button['url'])) {
        parse_str(parse_url($button['url'], PHP_URL_QUERY), $query);
        echo $query['action'];
        exit;
    }
}
throw new RuntimeException('CONFIRMATION_LINK_MISSING');
