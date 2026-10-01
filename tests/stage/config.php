<?php
// Loaded only from the disposable stage image. No production config is copied.
unset($CFG);
global $CFG;
$CFG = new stdClass();
if (__DIR__ !== '/stage/moodle') { throw new RuntimeException('REFUSING_NON_ISOLATED_MOODLE'); }
$prefix = getenv('USTAR_STAGE_PREFIX') ?: 'stage_';
if (!in_array($prefix, ['stage_', 'fresh_', 'pr63_'], true)) { throw new RuntimeException('INVALID_STAGE_PREFIX'); }
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
require_once(__DIR__ . '/lib/setup.php');
