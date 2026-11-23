"""Upgrade a verified briefing, Workplace or review deployment to Workplace Home."""

REVIEW = TARGET
REVIEW_STATE = STATE
HOME_REVIEW_MANIFEST = 'ab371a36c9fe5a66aae8a7e0fac43410309d912a2e4cc8fced1d69a4aa4df4ed'
TARGET = '24b304914d51e2cb7d11f76305aa3734a635bfa6'
AFTER = 2026112301
STATE = Path('/var/lib/ustar-workplace-home-20261123')
VERSIONS = {**VERSIONS, REVIEW: 2026112300}
load_review_package = load_package


def load_package(folder, core, engine, worker):
    manifest, package = load_review_package(folder, core, engine, worker)
    previous = manifest['review_source']
    engine.require(engine.digest_index(previous), 'invalid_review_source')
    engine.require(set(engine.changes(previous, manifest['after']))
                   <= set(engine.changes(manifest['before'][BASELINE], manifest['after'])), 'incomplete_home_payload')
    manifest['before'][REVIEW] = previous
    return manifest, package


def review_baseline(engine):
    if not os.path.lexists(REVIEW_STATE):
        return prior_baseline(engine)
    directory = REVIEW_STATE.lstat()
    engine.require(stat.S_ISDIR(directory.st_mode) and directory.st_uid == os.geteuid()
                   and stat.S_IMODE(directory.st_mode) == 0o700, 'unsafe_review_journal_directory')
    path = REVIEW_STATE / 'state.json'
    engine.require(os.path.lexists(path), 'missing_review_journal')
    entry = path.lstat()
    engine.require(stat.S_ISREG(entry.st_mode) and entry.st_uid == os.geteuid() and entry.st_nlink == 1
                   and stat.S_IMODE(entry.st_mode) == 0o600 and entry.st_size < 65536, 'unsafe_review_journal')
    state = json.loads(path.read_bytes())
    engine.require(state.get('package') == HOME_REVIEW_MANIFEST and state.get('baseline') in (BASELINE, WORKPLACE)
                   and state.get('step') in ('active', 'rolled_back'), 'unfinished_review_installation')
    expected = prior_baseline(engine)
    engine.require(expected is None or expected == state['baseline'], 'review_journal_predecessor_mismatch')
    return REVIEW if state['step'] == 'active' else state['baseline']


def configure(engine, schema_check):
    engine = configure_workplace(engine, schema_check)
    engine.BASES = tuple(VERSIONS)

    class Transaction(engine.Transaction):
        def select_baseline(self):
            global BEFORE
            state = self.read()
            source = state['baseline'] if state else self.host.inspect_source()
            engine.require(source in VERSIONS, 'application_source_drift')
            expected = review_baseline(engine)
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
