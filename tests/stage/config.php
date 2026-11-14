<?php
// Loaded only from the disposable stage image. No production config is copied.
unset($CFG);
global $CFG;
$CFG = new stdClass();
if (__DIR__ !== '/stage/moodle') { throw new RuntimeException('REFUSING_NON_ISOLATED_MOODLE'); }
$prefix = getenv('USTAR_STAGE_PREFIX') ?: 'stage_';
if (!in_array($prefix, ['stage_', 'fresh_', 'pr63_', 'botold_'], true)) { throw new RuntimeException('INVALID_STAGE_PREFIX'); }
$CFG->dbtype = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'db';
$CFG->dbname = 'ustar_stage1';
$CFG->dbuser = 'ustar_fixture';
$CFG->dbpass = 'fixture-only-not-a-production-secret';
$CFG->prefix = $prefix;
$CFG->dboptions = ['dbpersist' => false, 'dbport' => 5432, 'dbsocket' => '', 'dbcollation' => ''];
$httproot = getenv('USTAR_STAGE_HTTP_ROOT');
if ($httproot && $httproot !== 'http://127.0.0.1:8087') { throw new RuntimeException('INVALID_STAGE_HTTP_ROOT'); }
$CFG->wwwroot = $httproot ?: 'http://ustar-stage1.invalid';
$CFG->dataroot = '/stage/data/' . $prefix;
$CFG->admin = 'admin';
$CFG->directorypermissions = 02777;
$CFG->phpunit_prefix = 'phpu_';
$CFG->phpunit_dataroot = '/stage/phpunitdata';
$CFG->noemailever = true;
$CFG->disableupdatenotifications = true;
$CFG->upgradekey = 'fixture-only';
$broadcastfixture = getenv('USTAR_STAGE_BROADCAST_FIXTURE') === '1' && $prefix === 'stage_';
if ($broadcastfixture) {
    $CFG->wwwroot = 'https://127.0.0.1:18087';
}
require_once(__DIR__ . '/lib/setup.php');
if ($broadcastfixture) {
    // Core setup normalizes scalar config values to strings. The test uses typed Connect flags.
    $CFG->ustar_connect_channels = ['telegram' => ['tenant' => '123456', 'username' => 'ustarcorp_bot']];
    $CFG->ustar_connect_notifications_enabled = true;
    $CFG->ustar_connect_notify_since = 1;
    $CFG->ustar_connect_notification_presentation_enabled = true;
    $CFG->ustar_connect_ack_enabled = true;
    $CFG->ustar_connect_ack_since = 1;
    $CFG->ustar_connect_announcements_enabled = true;
    $CFG->ustar_connect_bridge_enabled = true;
    $CFG->ustar_connect_bridge_peers = ['127.0.0.1'];
    $CFG->ustar_connect_bridge_keys = ['tg_broadcast_fixture' => [
        'secret' => str_repeat('ab', 32), 'provider' => 'telegram', 'tenant' => '123456',
        'operations' => ['notification.lease', 'notification.begin_v3', 'notification.finish'],
    ]];
    $pilot = getenv('USTAR_STAGE_WIDGET_USERID');
    if ($pilot && preg_match('/\A[1-9][0-9]{0,10}\z/', $pilot)) {
        $CFG->ustar_connect_bot_enabled = true;
        $CFG->ustar_connect_checklist_widget_userids = [(int)$pilot];
        $CFG->ustar_connect_task_widget_userids = [(int)$pilot];
        $CFG->ustar_connect_bridge_keys['tg_widget_fixture'] = [
            'secret' => str_repeat('cd', 32), 'provider' => 'telegram', 'tenant' => '123456',
            'operations' => ['bot.update', 'bot.upload'],
        ];
    }
}
if ($httproot && (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/local/ustar/team_learning.php')) {
    set_exception_handler(static function (Throwable $error): void {
        error_log('Isolated team profile exception: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
        default_exception_handler($error);
    });
}
