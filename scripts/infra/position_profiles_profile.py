"""Install personal position models over the confirmed Workplace Assignment release."""

ASSIGNMENT = TARGET
ASSIGNMENT_STATE = STATE
ASSIGNMENT_MANIFEST = '601641a5a08ba94747931ccf98dcd8b73a19ae11a92832e4a3c1e380bb720d3b'
TARGET = '2d88d505c2c0b17ad4385bef009f98740f4d23f5'
BEFORE = 2026112302
AFTER = 2026112400
STATE = Path('/var/lib/ustar-position-profiles-20261124')
load_assignment_package = load_package

PROFILE_SCHEMA_CHECK = r'''
define('CLI_SCRIPT', true);
require "/var/www/html/config.php";
$schema = $DB->get_manager();
$results = [];
foreach ([
    'local_ustar_pos_profiles' => ['id', 'positionid', 'routeid', 'name', 'gradekey', 'gradeversionid', 'targetsjson',
        'revision', 'timecreated', 'timemodified', 'usermodified'],
    'local_ustar_pos_profile_users' => ['id', 'userid', 'positionid', 'profileid', 'staffassignmentid', 'revision', 'timemodified'],
] as $table => $columns) {
    if (!$schema->table_exists(new xmldb_table($table))) { $results[] = 'absent'; continue; }
    $actual = $DB->get_columns($table, false);
    $indexes = $DB->get_indexes($table);
    $unique = $table === 'local_ustar_pos_profiles'
        ? [['routeid'], ['positionid', 'name']] : [['userid']];
    foreach ($indexes as $index) {
        if ($index['unique']) {
            $unique = array_filter($unique, static fn($fields) => $fields !== $index['columns']);
        }
    }
    $results[] = !array_diff($columns, array_keys($actual)) && !$unique ? 'present' : 'invalid';
}
echo json_encode($results);
'''


def load_package(folder, core, engine, worker):
    manifest, package = load_assignment_package(folder, core, engine, worker)
    previous = manifest['assignment_source']
    engine.require(engine.digest_index(previous), 'invalid_assignment_source')
    engine.require(set(engine.changes(previous, manifest['after']))
                   <= set(engine.changes(manifest['before'][BASELINE], manifest['after'])), 'incomplete_profiles_payload')
    manifest['before'][ASSIGNMENT] = previous
    return manifest, package


def assert_assignment_ready(engine):
    engine.require(os.path.lexists(ASSIGNMENT_STATE), 'assignment_installation_not_confirmed')
    directory = ASSIGNMENT_STATE.lstat()
    engine.require(stat.S_ISDIR(directory.st_mode) and directory.st_uid == os.geteuid()
                   and stat.S_IMODE(directory.st_mode) == 0o700, 'unsafe_assignment_journal_directory')
    path = ASSIGNMENT_STATE / 'state.json'
    engine.require(os.path.lexists(path), 'missing_assignment_journal')
    entry = path.lstat()
    engine.require(stat.S_ISREG(entry.st_mode) and entry.st_uid == os.geteuid() and entry.st_nlink == 1
                   and stat.S_IMODE(entry.st_mode) == 0o600 and entry.st_size < 65536, 'unsafe_assignment_journal')
    state = json.loads(path.read_bytes())
    engine.require(isinstance(state, dict) and state.get('package') == ASSIGNMENT_MANIFEST
                   and state.get('step') == 'active' and state.get('baseline') in VERSIONS,
                   'assignment_installation_not_confirmed')
    prior = home_baseline(engine)
    engine.require(prior is None or prior == state['baseline'], 'assignment_journal_predecessor_mismatch')


def configure(engine, schema_check):
    engine = configure_workplace(engine, schema_check)
    engine.BASES = (ASSIGNMENT,)

    class Host(engine.Host):
        def verify(self, *, worker, active, interrupted=False):
            status = super().verify(worker=worker, active=active, interrupted=interrupted)
            verify_profile_schema(self, engine, status, interrupted)
            return status

    class Transaction(engine.Transaction):
        def check(self):
            assert_assignment_ready(engine)
            return super().check()

        def recover(self):
            assert_assignment_ready(engine)
            return super().recover()

    engine.Host, engine.Transaction = Host, Transaction
    return engine


def verify_profile_schema(host, engine, status, interrupted):
    tables = json.loads(host.php(['-r', PROFILE_SCHEMA_CHECK], 'position_profile_schema_check_failed'))
    if status['local_ustar'] == AFTER:
        engine.require(tables == ['present', 'present'], 'position_profile_schema_missing')
    elif interrupted:
        engine.require(len(tables) == 2 and all(table in ('absent', 'present') for table in tables),
                       'position_profile_schema_partial_drift')
    else:
        engine.require(tables == ['absent', 'absent'], 'unexpected_position_profile_schema')
