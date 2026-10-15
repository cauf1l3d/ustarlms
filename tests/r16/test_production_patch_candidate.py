"""Real toy archive/live-code copies; no production loader, Docker, SQL or PHP."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[2]


def load(name, file):
    spec = importlib.util.spec_from_file_location(name, REPO / file)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


s = load('prod_candidate_tested', 'scripts/infra/production_patch_candidate.py')
p = load('prod_candidate_read_primitives', 'scripts/infra/production_patch_readiness.py')
c = load('prod_candidate_archive_primitives', 'scripts/infra/lab_patch_candidate.py')


def sha(data):
    return hashlib.sha256(data).hexdigest()


class ConfigDiagnosticGuards(unittest.TestCase):
    def test_official_style_trailing_comments_are_preserved_after_safe_insertion(self):
        original = ("<?php\n$CFG->dbpass = 'PRIVATE_SENTINEL';\n" + s.SETUP
                    + " // Do not edit\n\n// No closing tag\n/* PRIVATE_COMMENT */\n").encode()
        report = s.config_diagnostic(original)
        self.assertEqual(report['failed_guard_conditions'], [])
        self.assertTrue(report['existing_insertion_guard_accepts'])
        self.assertEqual(report['suffix_after_unique_exact_setup'], 'comments_and_whitespace')
        self.assertNotIn('PRIVATE_SENTINEL', json.dumps(report))
        self.assertNotIn('PRIVATE_COMMENT', json.dumps(report))
        candidate = s.configuration(original)
        index = original.index(s.SETUP.encode())
        self.assertEqual(candidate, original[:index] + s.HOME.encode() + original[index:])
        self.assertEqual(candidate.replace(s.HOME.encode(), b'', 1), original)

    def test_known_public_path_is_observed_but_never_approved(self):
        original = b"<?php\nrequire_once(__DIR__ . '/public/lib/setup.php');\n"
        report = s.config_diagnostic(original)
        self.assertEqual(report['known_raw_setup_spelling_counts']['public_lib_spaced_single_quote'], 1)
        self.assertFalse(report['existing_insertion_guard_accepts'])
        self.assertEqual(report['suffix_after_unique_exact_setup'], 'not_applicable')

    def test_raw_lookalikes_in_passwords_or_comments_are_not_reported_as_PHP_semantics(self):
        original = ("<?php\n$CFG->dbpass = \"PRIVATE_SENTINEL " + s.SETUP + "\";\n"
                    "// $CFG->defaulthomepage\n" + s.SETUP + '\n').encode()
        report = s.config_diagnostic(original)
        self.assertEqual(report['exact_setup_raw_occurrences'], 2)
        self.assertFalse(report['guard_conditions']['homepage_raw_token_absent'])
        self.assertIn('not_full_PHP_validation', report['observations_scope'])
        self.assertNotIn('PRIVATE_SENTINEL', json.dumps(report))
        with self.assertRaises(s.CandidateRefusal):
            s.configuration(original)

    def test_code_unterminated_comment_and_closing_tag_have_fixed_labels_only(self):
        for suffix, expected in (("\n$PRIVATE = 'PRIVATE_SENTINEL';", 'other_text'),
                                 ('\n/* PRIVATE_SENTINEL', 'unterminated_block_comment'),
                                 ('\n// PRIVATE_SENTINEL\n?> \n', 'closing_tag_with_optional_comments_before_it')):
            report = s.config_diagnostic(('<?php\n' + s.SETUP + suffix).encode())
            self.assertEqual(report['suffix_after_unique_exact_setup'], expected)
            self.assertNotIn('PRIVATE_SENTINEL', json.dumps(report))
            self.assertFalse(report['existing_insertion_guard_accepts'])

    def test_non_utf8_has_no_exception_detail_or_config_bytes(self):
        report = s.config_diagnostic(b'<?php PRIVATE_SENTINEL\xff')
        self.assertFalse(report['utf8_decodable'])
        self.assertFalse(report['existing_insertion_guard_accepts'])
        self.assertNotIn('PRIVATE_SENTINEL', json.dumps(report))

    def test_cli_diagnostic_only_reads_bounded_config_no_archive_SQL_or_namespace(self):
        primitive = mock.Mock(CODE=Path('/opt/ustar/data/moodle/public'))
        primitive.read_file.return_value = b"<?php\n$CFG->dbpass='PRIVATE_SENTINEL';\n" + s.SETUP.encode()
        with mock.patch.object(s, 'load_module', return_value=primitive) as loader, \
                mock.patch.object(s, 'dependencies', side_effect=AssertionError('no archive/Docker/SQL')), \
                mock.patch.object(s, 'own_lock', side_effect=AssertionError('no namespace write')), \
                mock.patch.object(s.os, 'geteuid', return_value=0), \
                mock.patch.object(s.os, 'umask'), mock.patch.object(s.sys, 'dont_write_bytecode'), \
                mock.patch.object(s.sys, 'argv', ['candidate', '--diagnose-config']), \
                mock.patch.object(s.sys, 'stdout', new_callable=io.StringIO) as output:
            self.assertEqual(s.main(), 0)
        loader.assert_called_once_with(s.READINESS, s.READINESS_SHA, 'production_candidate_pinned_readiness')
        primitive.read_file.assert_called_once_with(primitive.CODE / 'config.php', 128 * 1024)
        self.assertNotIn('PRIVATE_SENTINEL', output.getvalue())
        self.assertIn('READ_ONLY; NO_CONFIG_TEXT', output.getvalue())
        report = json.loads(output.getvalue().split('\n', 1)[1])
        self.assertFalse(report['SQL_executed'])
        self.assertFalse(report['candidate_created_or_activated'])


class ConfigInsertionGuards(unittest.TestCase):
    @unittest.skipUnless(shutil.which('php'), 'PHP CLI available in source CI; local Python image has no PHP')
    def test_synthetic_PHP_setup_sees_only_homepage_change_and_same_config_values(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'lib').mkdir()
            (root / 'lib/setup.php').write_text("<?php echo json_encode(["
                "'password_hash'=>hash('sha256', $CFG->dbpass),"
                "'prefix'=>$CFG->prefix,'homepage'=>$CFG->defaulthomepage ?? null]);\n")
            original = ("<?php\n$CFG = new stdClass();\n"
                        "$CFG->dbpass = 'PRIVATE_SENTINEL \\' /* // # ?>';\n"
                        "$CFG->prefix = 'mdl_';\n" + s.SETUP
                        + ' // Do not edit\r\n/* PRIVATE_COMMENT */\n').encode()
            path = root / 'config.php'
            results = []
            for data in (original, s.configuration(original)):
                path.write_bytes(data)
                result = subprocess.run(['php', '-n', str(path)], text=True,
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10, check=True)
                self.assertEqual(result.stderr, '')
                self.assertNotIn('PRIVATE_SENTINEL', result.stdout)
                results.append(json.loads(result.stdout))
            self.assertIsNone(results[0]['homepage'])
            self.assertEqual(results[1], dict(results[0], homepage='/local/ustar/home.php'))

    def test_comments_newline_styles_and_values_are_byte_preserved(self):
        for newline in ('\n', '\r\n'):
            for tail in (' // Do not edit\n// Final comment\n', '\n# Final comment\n/* Block */\n', '\n'):
                original = ("<?php\n$CFG->dbpass = 'PRIVATE_SENTINEL \\' /* // # ?>';\n"
                            + s.SETUP + tail).replace('\n', newline).encode()
                changed = s.configuration(original)
                index = original.index(s.SETUP.encode())
                self.assertEqual(changed, original[:index] + s.HOME.encode() + original[index:])
                self.assertEqual(changed.replace(s.HOME.encode(), b'', 1), original)

    def test_setup_inside_a_literal_or_comment_is_never_an_insertion_site(self):
        for original in ("<?php\n// " + s.SETUP + '\n// trailing\n',
                         "<?php\n/* " + s.SETUP + '\n*/\n',
                         "<?php\n$CFG->dbpass = \"" + s.SETUP + '\";\n// tail\n'):
            with self.assertRaises(s.CandidateRefusal):
                s.configuration(original.encode())

    def test_controlled_or_nonstandalone_setup_is_refused(self):
        for prefix in ("<?php\nif (true)\n", "<?php\nif (true) {\n",
                       "<?php\nfor (;;)\n", "<?php\ndo\n",
                       "<?php\n$CFG->x=1; ", "<?php\n$CFG->x=(1;\n"):
            with self.assertRaises(s.CandidateRefusal):
                s.configuration((prefix + s.SETUP + '\n// tail\n').encode())

    def test_post_setup_PHP_tags_and_code_cannot_hide_in_line_comments(self):
        prefix = "<?php\n$CFG->prefix='mdl_';\n" + s.SETUP
        for tail in (' // comment\r$CFG->x=1;', ' # comment\r$CFG->x=1;',
                     ' // ?> secret output', '\n?>\n', '\n<?php\n',
                     '\n/* unfinished', '\n#[Attribute]', '\n\u00a0'):
            with self.assertRaises(s.CandidateRefusal):
                s.configuration((prefix + tail).encode())

    def test_unsupported_prefix_constructs_refuse_instead_of_guessing(self):
        for body in ('$CFG->x = <<<END\nhello\nEND;\n',
                     '$CFG->x = "{$complex[\"key\"]}";\n',
                     '#[Attribute]\nfunction foo() {};\n',
                     '$CFG->x=1;\n?>\n<?php\n', '$CFG->x=1;\n/* unfinished\n'):
            with self.assertRaises(s.CandidateRefusal):
                s.configuration(('<?php\n' + body + s.SETUP + '\n// tail\n').encode())


class ProductionCandidateGuards(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name)
        self.live, self.out, self.inputs = (self.base / name for name in ('live', 'candidates', 'inputs'))
        for root in (self.live, self.out, self.inputs):
            root.mkdir(mode=0o700)
        self.stock = {'public/index.php': b'<?php // official index\n',
                      'public/version.php': b'<?php $version = 2025100608.00;\n',
                      'public/config.php': b'<?php // official public config loader\n',
                      'public/lib/setup.php': b'<?php // setup\n',
                      'composer.json': b'{}\n', 'composer.lock': b'{}\n',
                      'githash.php': b'<?php // official provenance\n'}
        self.config = ("<?php\n$CFG->wwwroot = 'http://production.invalid';\n"
                       "$CFG->dbpass = 'PRIVATE_SENTINEL';\n$CFG->prefix = 'mdl_';\n"
                       "require_once(__DIR__ . '/lib/setup.php');\n").encode()
        original = dict(self.stock, **{'config.php': self.config,
                       'public/index.php': b'<?php // original custom home\n',
                       'public/version.php': b'<?php $version = 2025100601.03;\n',
                       'githash.php': b'<?php // old provenance\n',
                       'config.php.backup': b'PRIVATE_BACKUP',
                       'public/local/ustar/home.php': b'<?php // live addon\n'})
        for rel, data in original.items():
            path = self.live / rel
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        os.chmod(self.live / 'config.php', 0o600)
        self.archive = self.inputs / 'moodle-5.1.8.tgz'
        with tarfile.open(self.archive, 'w:gz') as tar:
            dirs = {'moodle'}
            for rel in self.stock:
                dirs.update('moodle/' + str(path) for path in Path(rel).parents if str(path) != '.')
            for name in sorted(dirs, key=lambda x: (x.count('/'), x)):
                member = tarfile.TarInfo(name)
                member.type, member.mode = tarfile.DIRTYPE, 0o755
                tar.addfile(member)
            for rel, data in self.stock.items():
                member = tarfile.TarInfo('moodle/' + rel)
                member.size, member.mode = len(data), 0o644
                tar.addfile(member, io.BytesIO(data))
        patches = [(s, 'BASE', self.out), (p, 'CODE', self.live),
                   (c, 'SOURCE', self.inputs), (c, 'PACKAGE_DIR', self.inputs),
                   (c, 'ARCHIVE', self.archive), (c, 'ARCHIVE_SHA', sha(self.archive.read_bytes())),
                   (c, 'ROOT_UID', os.getuid()), (c, 'ROOT_GID', os.getgid()),
                   (c, 'EXTRAS', ('config.php.backup', 'githash.php')),
                   (c, 'CORE_HASHES', {rel: sha(self.stock[rel]) for rel in
                     ('public/index.php', 'public/version.php', 'composer.json', 'composer.lock')}),
                   (c, 'reserve', lambda: None), (p, 'containers', lambda: {'unchanged': True}),
                   (p, 'versions', lambda *args: {'core': '2025100601.03', 'addons': {'local_ustar': '2026100204'}, 'enabled_tasks': 129})]
        for module, name, value in patches:
            patch = mock.patch.object(module, name, value)
            patch.start()
            self.addCleanup(patch.stop)
        self.initial = s.scan_code(p, self.live)
        patch = mock.patch.object(s, 'PUBLIC_CONFIG_HASHES', {self.initial['public/config.php']['sha256']})
        patch.start()
        self.addCleanup(patch.stop)
        self.source_bytes = {rel: (self.live / rel).read_bytes() for rel, row in self.initial.items() if row['type'] == 'file'}

    def checked(self, tar):
        live = s.scan_code(p, self.live)
        selected = {rel: row for rel, row in live.items()
                    if rel in ('public', 'public/local', 'public/local/ustar', 'config.php.backup', 'githash.php')
                    or rel.startswith('public/local/ustar/')}
        return ({'unchanged': True}, {'additional_roots': ['public/local/ustar']},
                self.config, s.configuration(self.config), p.versions(), live, selected,
                c.archive_catalog(tar), {'copy_only': True})

    def execute(self):
        with self.archive.open('rb') as stream, tarfile.open(fileobj=stream, mode='r:gz') as tar:
            info = self.archive.stat()
            return s.prepare(p, c, tar, self.checked(tar), stream, info)

    def assert_live_unchanged(self):
        self.assertEqual(s.scan_code(p, self.live), self.initial)
        self.assertEqual({rel: (self.live / rel).read_bytes() for rel in self.source_bytes}, self.source_bytes)

    def test_real_copy_preserves_credentials_and_stock_index_without_activation(self):
        report = self.execute()
        root = Path(report['candidate_directory'])
        self.assert_live_unchanged()
        self.assertEqual((root / 'code/public/index.php').read_bytes(), self.stock['public/index.php'])
        self.assertEqual((root / 'code/public/local/ustar/home.php').read_bytes(), self.source_bytes['public/local/ustar/home.php'])
        self.assertEqual((root / 'code/config.php').read_bytes(), s.configuration(self.config))
        self.assertEqual((root / 'preserved-original/config.php').read_bytes(), self.config)
        self.assertEqual((root / 'preserved-original/public-index.php').read_bytes(), self.source_bytes['public/index.php'])
        self.assertEqual((root / 'preserved-original/githash.php').read_bytes(), self.source_bytes['githash.php'])
        self.assertFalse(report['candidate_activated'])
        self.assertNotIn('PRIVATE_SENTINEL', json.dumps(report))
        manifest = (root / 'candidate-manifest.json').read_bytes()
        self.assertEqual(sha(manifest), report['candidate_manifest_sha256'])

    def test_real_candidate_preserves_post_setup_comments_and_original_config(self):
        self.config += b'// Do not edit\r\n/* PRIVATE_COMMENT */\n'
        (self.live / 'config.php').write_bytes(self.config)
        self.initial = s.scan_code(p, self.live)
        self.source_bytes['config.php'] = self.config
        report = self.execute()
        root = Path(report['candidate_directory'])
        self.assert_live_unchanged()
        self.assertEqual((root / 'preserved-original/config.php').read_bytes(), self.config)
        changed = (root / 'code/config.php').read_bytes()
        self.assertEqual(changed.replace(s.HOME.encode(), b'', 1), self.config)
        self.assertTrue(changed.endswith(b'/* PRIVATE_COMMENT */\n'))
        self.assertNotIn('PRIVATE_COMMENT', json.dumps(report))

    def test_copy_failure_retains_new_directory_and_live_code(self):
        with mock.patch.object(s, 'copy_live', side_effect=RuntimeError('injected')):
            with self.assertRaises(RuntimeError):
                self.execute()
        self.assert_live_unchanged()
        roots = list(self.out.glob('candidate-*'))
        self.assertEqual(len(roots), 1)
        self.assertFalse((roots[0] / 'candidate-manifest.json').exists())

    def test_source_drift_refuses_complete_manifest(self):
        original = s.verify
        def drift(*args):
            original(*args)
            (self.live / 'public/local/ustar/home.php').write_bytes(b'changed by another actor')
        with mock.patch.object(s, 'verify', side_effect=drift):
            with self.assertRaisesRegex(s.CandidateRefusal, 'Live production code/config changed'):
                self.execute()
        self.assertFalse(next(self.out.glob('candidate-*')).joinpath('candidate-manifest.json').exists())
        self.assertEqual((self.live / 'config.php').read_bytes(), self.config)

    def test_unexpected_file_and_core_change_refused(self):
        frozen = {c.PREFIX + rel: row for rel, row in self.initial.items()}
        selected = {'public/local/ustar/home.php': self.initial['public/local/ustar/home.php']}
        additions = dict(self.initial, unexpected={'type': 'file'})
        with self.assertRaisesRegex(s.CandidateRefusal, 'inventory differs'):
            s.baseline_guard(c, additions, frozen, selected)
        changed = dict(self.initial)
        changed['composer.json'] = dict(changed['composer.json'], sha256='0' * 64)
        with self.assertRaisesRegex(s.CandidateRefusal, 'code file changed'):
            s.baseline_guard(c, changed, frozen, selected)
        changed = dict(self.initial)
        changed['public/local/ustar/home.php'] = dict(changed['public/local/ustar/home.php'], sha256='0' * 64)
        with self.assertRaisesRegex(s.CandidateRefusal, 'code file changed'):
            s.baseline_guard(c, changed, frozen, selected)

    def test_config_insertion_rejects_dynamic_or_duplicate_home(self):
        for value in (self.config.replace(b'require_once', b'require'), self.config + b'echo 1;',
                      self.config.replace(b'<?php', b"<?php\n$CFG->defaulthomepage = '/other';")):
            with self.assertRaises(s.CandidateRefusal):
                s.configuration(value)

    def test_unknown_public_config_wrapper_is_not_treated_as_ordinary_config(self):
        frozen = {c.PREFIX + rel: row for rel, row in self.initial.items()}
        changed = dict(self.initial)
        changed['public/config.php'] = dict(changed['public/config.php'], sha256='0' * 64)
        with self.assertRaisesRegex(s.CandidateRefusal, 'public config loader needs separate review'):
            s.baseline_guard(c, changed, frozen, {})

    def test_collision_is_refused_before_overlay_copy(self):
        with mock.patch.object(c, 'EXTRAS', (*c.EXTRAS, 'public/index.php')):
            entries = {'public/index.php': self.initial['public/index.php']}
            with tarfile.open(self.archive) as tar:
                with self.assertRaises(c.CandidateError):
                    c.route_overlay(entries, c.archive_catalog(tar))

    def test_repeated_copy_creates_distinct_independent_candidates(self):
        first, second = self.execute(), self.execute()
        self.assertNotEqual(first['candidate_directory'], second['candidate_directory'])
        self.assert_live_unchanged()
        for report in (first, second):
            target = Path(report['candidate_directory']) / 'code/config.php'
            self.assertNotEqual(target.stat().st_ino, (self.live / 'config.php').stat().st_ino)

    def test_candidate_corruption_fails_readback(self):
        original = c.write_file
        def corrupt(path, *args, **kwargs):
            result = original(path, *args, **kwargs)
            if str(path).endswith('/code/public/local/ustar/home.php'):
                path.write_bytes(b'corrupted')
            return result
        with mock.patch.object(c, 'write_file', side_effect=corrupt):
            with self.assertRaisesRegex(s.CandidateRefusal, 'readback inventory'):
                self.execute()
        self.assert_live_unchanged()

    def test_package_mutation_during_extraction_refused(self):
        original = c.extract_stock
        def drift(*args):
            value = original(*args)
            self.archive.write_bytes(b'changed archive')
            return value
        with mock.patch.object(c, 'extract_stock', side_effect=drift):
            with self.assertRaisesRegex(s.CandidateRefusal, 'archive changed during extraction'):
                self.execute()
        self.assert_live_unchanged()
        self.assertFalse(next(self.out.glob('candidate-*')).joinpath('candidate-manifest.json').exists())
