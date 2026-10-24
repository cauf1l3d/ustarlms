"""Pure v2/ACK profiles and real public-code release staging."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import sys
from types import SimpleNamespace
import unittest
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/infra'))
import connect_con02_contract as c
import connect_con02_state as s


class Profiles(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        from build_connect_con02_update import reference_from_git
        cls.bundle = reference_from_git(ROOT, '7f833626f687a8baa8e98efaba7e4d3293b1b47a')
        cls.legacy = c.frozen_legacy(cls.bundle)

    def setUp(self):
        self.ctx = c.Context(self.bundle, Path('/'), 0, None, None)
        tenant = '123456'; link = '11' * 32; notify = '22' * 32
        include = self.legacy['connect_private_bridge'].php_settings(link, tenant)
        include += ("\n// USTAR one-employee notification pilot; separate key and new events only.\n"
            "$CFG->ustar_connect_bridge_keys['tg_notify_pilot_v1']=['secret'=>'"+notify+
            "','provider'=>'telegram','tenant'=>'"+tenant+"','operations'=>['notification.lease','notification.begin','notification.finish']];\n"
            "$CFG->ustar_connect_notify_userids=[130];\n$CFG->ustar_connect_notify_since=100;\n"
            "$CFG->ustar_connect_notifications_enabled=true;\n").encode()
        files = {'include': include, 'link_key': (link+'\n').encode(), 'notify_key': (notify+'\n').encode(),
                 'notify_dropin': self.legacy['connect_notification_pilot'].DROPIN}
        for unit in ('receiver', 'probe', 'notifications'):
            service = 'notify' if unit == 'notifications' else unit
            cred = '/run/credentials/ustar-connect-' + service + '.service/'
            cfg = {'bot_token_file': cred+'telegram.token', 'bot_username': 'ustarcorp_bot',
                'state_directory': '/var/lib/ustar-connect-' + ('notify' if service=='notify' else 'receiver'),
                'telegram_proxy': 'http://127.0.0.1:18081', 'onboarding_enabled': False,
                'linking_enabled': service != 'notify', 'bridge_endpoint': 'https://127.0.0.1:18443/local/ustar/connect_bridge.php',
                'bridge_key_id': 'tg_notify_pilot_v1' if service=='notify' else 'tg_link_v1',
                'bridge_key_file': cred+'bridge.key', 'bridge_ca_file': cred+('bridge-ca.crt' if service=='notify' else 'bridge.ca')}
            if service=='notify': cfg['notification_subjects']=['12345']
            files[unit] = (json.dumps(cfg, sort_keys=True)+'\n').encode()
        self.before = {'files': {n: {'raw': raw} for n, raw in files.items()},
                       'fence': c.read_fence(include, json.loads(files['notifications']), json.loads(files['receiver']))}
        self.before['fence']['last_link_state'] = 'revoked'

    def module(self):
        self.assertIsNotNone(importlib.util.find_spec('connect_con02_profiles'), 'exact ACK profile interface')
        import connect_con02_profiles as p
        return p

    def profile(self, **kw):
        return self.module().profiles(self.ctx, self.before, generation='a'*32, cutoff=200,
            notify_secret=kw.get('notify_secret', b'33'*32), ack_secret=kw.get('ack_secret', b'44'*32))

    def test_preserves_revoked_uid_subject_and_notify_cutoff(self):
        after = self.profile()
        self.assertTrue(after['include'].startswith(self.before['files']['include']['raw']))
        self.assertEqual(self.before['fence']['notification_pilot_userids'],
                         c.read_fence(after['include'], json.loads(after['notifications']))['notification_pilot_userids'])
        self.assertEqual(100, c.read_fence(after['include'], json.loads(after['notifications']))['notify_since'])
        self.assertEqual(['12345'], json.loads(after['notifications'])['notification_subjects'])
        self.assertIn(b"['notification.ack']", after['include'])

    def test_keys_are_distinct_from_link_and_each_other(self):
        for n, a in [(b'11'*32, b'44'*32), (b'33'*32, b'33'*32), (b'ZZ'*32, b'44'*32)]:
            with self.subTest(n=n[:2]), self.assertRaises(c.Refusal): self.profile(notify_secret=n, ack_secret=a)

    def test_separate_mount_paths(self):
        after = self.profile()
        sender = json.loads(after['notifications'])
        self.assertEqual(2, sender['notification_contract']); self.assertNotIn('ack_bridge_key_file', sender)
        for unit in ('receiver', 'probe'):
            cfg = json.loads(after[unit])
            self.assertEqual('/run/credentials/ustar-connect-'+unit+'.service/ack-bridge.key', cfg['ack_bridge_key_file'])
            self.assertEqual('tg_ack_v1', cfg['ack_bridge_key_id'])
        self.assertNotIn(b'ack', after['notify_dropin'])

    def test_unknown_configuration_is_rejected(self):
        cfg = json.loads(self.before['files']['receiver']['raw']); cfg['unknown'] = True
        self.before['files']['receiver']['raw'] = json.dumps(cfg).encode()
        with self.assertRaises(c.Refusal): self.profile()

    def test_new_release_access_under_umask077(self):
        self.assertIsNotNone(importlib.util.find_spec('connect_con02_runtime'), 'release stage interface')
        from connect_con02_runtime import stage_worker
        with tempfile.TemporaryDirectory() as td:
            root = Path(td); (root/'opt/ustar-connect/releases').mkdir(parents=True)
            ctx = c.Context(self.bundle, root, os.getuid(), SimpleNamespace(validate_units=lambda values: None), None)
            old = os.umask(0o077)
            try: release = stage_worker(ctx)
            finally: os.umask(old)
            self.assertEqual(0o755, release.stat().st_mode & 0o777)
            self.assertEqual(0o644, (release/'receiver.pyz').stat().st_mode & 0o777)
            self.assertEqual(release, stage_worker(ctx))
            (release/'receiver.pyz').write_bytes(b'collision')
            with self.assertRaises(c.Refusal): stage_worker(ctx)


if __name__ == '__main__': unittest.main()
