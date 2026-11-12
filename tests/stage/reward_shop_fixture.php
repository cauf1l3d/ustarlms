<?php
if (getenv('USTAR_STAGE_PREFIX') !== 'stage_') { throw new RuntimeException('ISOLATED_PREFIX_REQUIRED'); }
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
require_once($CFG->dirroot.'/user/lib.php');

$tree = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$tree['departments'][] = ['id'=>'reward_fixture', 'name'=>'Отдел проверки наград'];
$tree['positions'][] = ['id'=>'reward_fixture_job', 'name'=>'Сотрудник проверки наград',
    'department'=>'reward_fixture', 'level'=>1];
\local_ustar\structure::save(\local_ustar\structure::NAME_STRUCTURE, $tree);
if (!$DB->record_exists('user_info_field', ['shortname'=>'ustar_position'])) {
    $id = \local_ustar\accounts::ensure_profile_field();
    $categoryid = $DB->get_field('user_info_field', 'categoryid', ['id'=>$id], MUST_EXIST);
    $DB->insert_record('user_info_field', (object)[
        'shortname'=>'ustar_position', 'name'=>'Должность', 'datatype'=>'text',
        'description'=>'', 'categoryid'=>$categoryid, 'sortorder'=>99,
        'visible'=>0, 'locked'=>1, 'defaultdata'=>'', 'param1'=>30, 'param2'=>255,
    ]);
}
$userid = user_create_user((object)[
    'username'=>'rewardfixture', 'auth'=>'manual', 'password'=>'Fixture-Only-Password1!',
    'confirmed'=>1, 'mnethostid'=>$CFG->mnet_localhost_id,
    'firstname'=>'Сотрудник', 'lastname'=>'Тестовый', 'email'=>'rewardfixture@example.invalid',
]);
\local_ustar\people::set_position_id($userid, 'reward_fixture_job');
unset_user_preference('auth_forcepasswordchange', $userid);
\local_ustar\economy::post($userid, 20, 'fixture', 'fixture-reward-shop', '', '', '', (int)get_admin()->id);
file_put_contents('/artifacts/reward-shop-fixture.json', json_encode(['employee' => $userid], JSON_THROW_ON_ERROR));
