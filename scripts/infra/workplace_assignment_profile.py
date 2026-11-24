"""Add Workplace Home as a baseline for the task-assignment installer."""

HOME = TARGET
HOME_STATE = STATE
ASSIGNMENT_HOME_MANIFEST = '1bc2153f56ca8ac5ce4df324b42fca97250a0e8a91e4506420bdcf651c6f3528'
TARGET = 'fdb6dc414e53931d750eb63dbbedb331cee1c7d8'
AFTER = 2026112302
STATE = Path('/var/lib/ustar-workplace-assignment-20261123')
VERSIONS = {**VERSIONS, HOME: 2026112301}
load_home_package = load_package


def load_package(folder, core, engine, worker):
    manifest, package = load_home_package(folder, core, engine, worker)
    previous = manifest['home_source']
    engine.require(engine.digest_index(previous), 'invalid_home_source')
    engine.require(set(engine.changes(previous, manifest['after']))
                   <= set(engine.changes(manifest['before'][BASELINE], manifest['after'])), 'incomplete_assignment_payload')
    manifest['before'][HOME] = previous
    return manifest, package


def home_baseline(engine):
    expected = review_baseline(engine)
    if not os.path.lexists(HOME_STATE):
        return expected
    directory = HOME_STATE.lstat()
    engine.require(stat.S_ISDIR(directory.st_mode) and directory.st_uid == os.geteuid()
                   and stat.S_IMODE(directory.st_mode) == 0o700, 'unsafe_home_journal_directory')
    path = HOME_STATE / 'state.json'
    engine.require(os.path.lexists(path), 'missing_home_journal')
    entry = path.lstat()
    engine.require(stat.S_ISREG(entry.st_mode) and entry.st_uid == os.geteuid() and entry.st_nlink == 1
                   and stat.S_IMODE(entry.st_mode) == 0o600 and entry.st_size < 65536, 'unsafe_home_journal')
    state = json.loads(path.read_bytes())
    engine.require(isinstance(state, dict) and state.get('package') == ASSIGNMENT_HOME_MANIFEST
                   and state.get('baseline') in (BASELINE, WORKPLACE, REVIEW)
                   and state.get('step') in ('active', 'rolled_back'), 'unfinished_home_installation')
    engine.require(expected is None or expected == state['baseline'], 'home_journal_predecessor_mismatch')
    return HOME if state['step'] == 'active' else state['baseline']


def configure(engine, schema_check):
    engine = configure_workplace(engine, schema_check)
    engine.BASES = tuple(VERSIONS)

    class Transaction(engine.Transaction):
        def select_baseline(self):
            global BEFORE
            state = self.read()
            source = state['baseline'] if state else self.host.inspect_source()
            engine.require(source in VERSIONS, 'application_source_drift')
            expected = home_baseline(engine)
            engine.require(expected is None or expected == source, 'predecessor_source_mismatch')
            BEFORE = engine.BEFORE = VERSIONS[source]

        def check(self):
            self.select_baseline()
            return super().check()

        def recover(self):
            self.select_baseline()
            return super().recover()

    engine.Transaction = Transaction
    return engine
