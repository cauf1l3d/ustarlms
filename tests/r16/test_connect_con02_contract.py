"""Fixed release and read-only contracts; no remote host/provider is used."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/infra'))


class Contract(unittest.TestCase):
    def modules(self):
        self.assertIsNotNone(importlib.util.find_spec('connect_con02_contract'), 'new contract interface')
        import connect_con02_contract as c
        import build_connect_con02_update as b
        return c, b

    def reference(self):
        c, b = self.modules()
        if not hasattr(self.__class__, 'bundle'):
            self.__class__.bundle = b.reference_from_git(ROOT, '7f833626f687a8baa8e98efaba7e4d3293b1b47a')
        return c, self.bundle

    def test_frozen_counts_and_hashes(self):
        c, bundle = self.reference()
        b, t = bundle.reference['baseline'], bundle.reference['candidate']
        self.assertEqual((552, 557, 99, 100), (len(b['files']), len(t['files']), len(b['tables']), len(t['tables'])))
        self.assertEqual({'local_ustar_conn_acktokens'}, set(t['tables']) - set(b['tables']))
        self.assertEqual(c.OVERLAY_SHA, b['files']['local/ustar/classes/connect/notification_delivery.php'])
        self.assertEqual(c.APP_COMMIT, bundle.reference['worker_commit'])
        self.assertEqual(set(t['files']), set(bundle.payload))
        self.assertTrue(bundle.worker_archive)

    def test_target_preserves_old99_and_custom_tasks(self):
        c, _ = self.modules()
        ref = {'baseline': {'tables': ['local_ustar_old'], 'versions': {'local_ustar': 2026102000}},
               'candidate': {'tables': ['local_ustar_new', 'local_ustar_old'], 'versions': {'local_ustar': 2026102200},
                             'schema_fields': {'local_ustar_new': {'id': {'type': 'int', 'length': '10', 'notnull': True}}},
                             'schema_indexes': {'local_ustar_new': [{'fields': ['id'], 'unique': True}]}}}
        before = {'core': c.CORE, 'addons': {'local_ustar': '2026102000', 'third_party': '42'},
                  'tasks': {'prune': [1, 1]}, 'tables': ['local_ustar_old'],
                  'schema': [['local_ustar_old', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None]],
                  'indexes': [['local_ustar_old', 'CREATE UNIQUE INDEX old ON mdl_local_ustar_old USING btree (id)']], 'other_clients': 0}
        after = copy.deepcopy(before)
        after['addons']['local_ustar'] = '2026102200'
        after['tables'] = ref['candidate']['tables']
        after['schema'].append(['local_ustar_new', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None])
        after['indexes'].append(['local_ustar_new', 'CREATE UNIQUE INDEX new ON mdl_local_ustar_new USING btree (id)'])
        c.verify_database(after, before, ref, target=True)
        for key, change in [('tasks', {'prune': [0, 0]}), ('indexes', after['indexes'][1:]),
                            ('schema', after['schema'][1:]), ('tables', after['tables'] + ['local_ustar_extra'])]:
            bad = copy.deepcopy(after); bad[key] = change
            with self.subTest(key=key), self.assertRaises(c.Refusal):
                c.verify_database(bad, before, ref, target=True)

    def test_prefix_injection_refused(self):
        c, _ = self.modules()
        with self.assertRaises(c.Refusal): c.validate_prefix('mdl_; DROP TABLE x')

    def test_revoked_pilot_is_valid_baseline(self):
        c, _ = self.modules()
        before = {'notification_pilot_userids': [130], 'notification_subjects': ['12345'],
                  'notify_since': 1792583817, 'notifications_enabled': True, 'linking_enabled': True,
                  'last_link_state': 'revoked'}
        self.assertEqual(before, c.validate_fence(before))
        with self.assertRaises(c.Refusal): c.validate_fence({**before, 'notification_pilot_userids': []})

    def test_unknown2200_is_not_adopted(self):
        c, _ = self.modules()
        with self.assertRaises(c.Refusal): c.select_target(None, {'local_ustar': '2026102200'})

    def test_authorized_renewal_between_operations(self):
        c, _ = self.modules()
        class Host:
            leaf = 'old'
            def https_snapshot(self): return {'verified': True, 'leaf': self.leaf, 'owner': 'https'}
        host = Host()
        ctx = c.Context(None, Path('/'), 0, host, None)
        old = c.https_snapshot(ctx); host.leaf = 'new'
        self.assertNotEqual(old, c.https_snapshot(ctx))
        with self.assertRaises(c.Refusal): c.require_https_unchanged(ctx, old)

    def test_readonly_has_no_business_or_nonce_writes(self):
        c, _ = self.modules()
        # A rootless read-only context cannot silently initialize a host adapter.
        import tempfile
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            with self.assertRaises(c.Refusal): c.check(c.Context(None, root, 1234, None, None))
            self.assertEqual([], list(root.iterdir()))


if __name__ == '__main__': unittest.main()
