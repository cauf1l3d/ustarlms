"""Successor profile embedded before the frozen Workplace installer's entry point."""

WORKPLACE = TARGET
TARGET = '341bbaed1799b4a8de9d5271a0e944efc1cd2e40'
VERSIONS = {BASELINE: 2026111800, WORKPLACE: 2026112200}
AFTER = 2026112300
PRIOR_STATE = STATE
STATE = Path('/var/lib/ustar-workplace-20261123')
PRIOR_MANIFEST = 'd25340c84b2386b3838757ab5431252dbe28cb2b08e351d0dd46a79ed81b6822'
configure_workplace = configure
load_workplace_package = load_package


def load_package(folder, core, engine, worker):
    manifest, package = load_workplace_package(folder, core, engine, worker)
    intermediate = manifest['workplace_source']
    engine.require(engine.digest_index(intermediate), 'invalid_workplace_source')
    delta = engine.changes(intermediate, manifest['after'])
    engine.require(set(delta) <= set(engine.changes(manifest['before'][BASELINE], manifest['after'])),
                   'incomplete_cumulative_payload')
    manifest['before'][WORKPLACE] = intermediate
    return manifest, package


def prior_baseline(engine):
    if not os.path.lexists(PRIOR_STATE):
        return None
    info = PRIOR_STATE.lstat()
    engine.require(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid()
                   and stat.S_IMODE(info.st_mode) == 0o700, 'unsafe_prior_journal_directory')
    path = PRIOR_STATE / 'state.json'
    engine.require(os.path.lexists(path), 'missing_prior_workplace_journal')
    info = path.lstat()
    engine.require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid() and info.st_nlink == 1
                   and stat.S_IMODE(info.st_mode) == 0o600 and info.st_size < 65536, 'unsafe_prior_journal')
    state = json.loads(path.read_bytes())
    engine.require(state.get('package') == PRIOR_MANIFEST and state.get('baseline') == BASELINE
                   and state.get('step') in ('active', 'rolled_back'), 'unfinished_prior_workplace_installation')
    return WORKPLACE if state['step'] == 'active' else BASELINE


def configure(engine, schema_check):
    engine = configure_workplace(engine, schema_check)
    engine.BASES = tuple(VERSIONS)

    class Transaction(engine.Transaction):
        def select_baseline(self):
            global BEFORE
            state = self.read()
            source = state['baseline'] if state else self.host.inspect_source()
            engine.require(source in VERSIONS, 'application_source_drift')
            prior = prior_baseline(engine)
            engine.require(prior is None or prior == source, 'prior_workplace_source_mismatch')
            BEFORE = engine.BEFORE = VERSIONS[source]

        def check(self):
            self.select_baseline()
            return super().check()

        def recover(self):
            self.select_baseline()
            return super().recover()

    engine.Transaction = Transaction
    return engine
