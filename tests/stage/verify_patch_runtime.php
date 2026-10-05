<?php
// Synthetic CI only: no Moodle bootstrap, production config or application data.
$profile = json_decode(file_get_contents('/opt/ustar-runtime.json'), true, 512, JSON_THROW_ON_ERROR);
if (PHP_VERSION !== $profile['php_version']) {
    throw new RuntimeException('PHP version differs from pinned patch runtime');
}
if ((int)ini_get('max_input_vars') < 5000) {
    throw new RuntimeException('Moodle requires max_input_vars >= 5000');
}
foreach (['intl', 'zip', 'pgsql', 'gd', 'soap', 'exif', 'sodium', 'Zend OPcache', 'curl',
        'mbstring', 'dom', 'xml', 'xmlreader'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException('Missing extension: ' . $extension);
    }
}
// version.php is metadata only, but requires these Moodle constants.
define('MOODLE_INTERNAL', true);
define('MATURITY_STABLE', 200);
require '/opt/moodle/public/version.php';
if (sprintf('%.2f', $version) !== $profile['moodle_version']) {
    throw new RuntimeException('Moodle version differs from pinned patch runtime');
}
$connection = pg_connect('host=db dbname=ustar_stage1 user=ustar_fixture');
if (!$connection) {
    throw new RuntimeException('Synthetic database connection failed');
}
$result = pg_query($connection, 'SHOW server_version_num');
if (!$result || ($pgversion = pg_fetch_result($result, 0, 0)) !== $profile['postgres_version_num']) {
    throw new RuntimeException('PostgreSQL version differs from pinned patch runtime');
}
pg_close($connection);
echo json_encode(['php' => PHP_VERSION, 'moodle' => $release, 'moodle_version' => $profile['moodle_version'],
    'postgres_version_num' => $pgversion, 'profile_verified' => true], JSON_PRETTY_PRINT), PHP_EOL;
