"""Real paired helper writes/recovery; no backup, Docker or systemd operation."""
import hashlib
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch,Mock

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'scripts')]
import backup_https_prepare as update
import hdd_backup as hdd


class Pair(unittest.TestCase):
    def setUp(self):
        tmp=tempfile.TemporaryDirectory();self.addCleanup(tmp.cleanup);self.root=Path(tmp.name)
        self.before={'producer':b'ORIGINAL=1\n','scheduler':b'ORIGINAL=2\n'}
        self.after={'producer':b'NEW=1\n','scheduler':b'NEW=2\n'}
        for name,value in (('BEFORE',{k:hashlib.sha256(v).hexdigest() for k,v in self.before.items()}),
                           ('AFTER',{k:hashlib.sha256(v).hexdigest() for k,v in self.after.items()})):
            p=patch.object(update,name,value);p.start();self.addCleanup(p.stop)
        self.m=update.Manager(self.after,self.root,os.getuid())
        self.m.p.base.parent.mkdir(parents=True,exist_ok=True)
        for name,path in self.m.paths.items():
            path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(self.before[name]);path.chmod(0o750)

    def test_check_is_readonly_and_apply_updates_both_preserving_metadata(self):
        self.assertEqual('ready_for_backup_HTTPS_compatibility',self.m.check()['phase'])
        self.assertFalse(self.m.p.base.exists())
        self.assertEqual('backup_HTTPS_compatibility_prepared',self.m.apply()['phase'])
        self.assertEqual('already_applied',self.m.apply()['phase'])
        for name,path in self.m.paths.items():
            self.assertEqual(self.after[name],path.read_bytes());self.assertEqual(0o750,path.stat().st_mode&0o7777)

    def test_failure_between_pair_writes_restores_both_before_images(self):
        original=self.m.writer.replace
        def broken(path,raw,meta):
            if path==self.m.paths['scheduler'] and raw==self.after['scheduler']:raise OSError('fixture failure')
            return original(path,raw,meta)
        with patch.object(self.m.writer,'replace',side_effect=broken):
            with self.assertRaises(OSError):self.m.apply()
        self.assertEqual(update.BEFORE,self.m.snapshot());self.assertEqual('rolled_back',self.m.record()['phase'])

    def test_administrator_drift_is_not_overwritten(self):
        self.m.apply();self.m.paths['producer'].write_bytes(b'admin changes')
        with self.assertRaises(Exception):self.m.restore(self.m.record())
        self.assertEqual(b'admin changes',self.m.paths['producer'].read_bytes())
        self.assertEqual(self.after['scheduler'],self.m.paths['scheduler'].read_bytes())

    def test_damaged_before_image_refuses_before_any_restore_write(self):
        self.m.apply();(self.m.p.base/'scheduler.before').write_bytes(b'damaged')
        with self.assertRaises(Exception):self.m.restore(self.m.record())
        self.assertEqual(update.AFTER,self.m.snapshot())

    def test_private_before_images_and_explicit_restore(self):
        self.m.apply()
        self.assertEqual(0o600,(self.m.p.base/'producer.before').stat().st_mode&0o7777)
        self.m.restore(self.m.record());self.assertEqual(update.BEFORE,self.m.snapshot())

    def test_unknown_source_symlink_or_write_permissions_refuse(self):
        path=self.m.paths['producer'];raw=path.read_bytes();path.write_bytes(b'wrong source')
        with self.assertRaises(Exception):self.m.check()
        path.write_bytes(raw);path.chmod(0o770)
        with self.assertRaises(Exception):self.m.check()
        path.chmod(0o750);other=path.with_suffix('.original');path.rename(other);path.symlink_to(other)
        with self.assertRaises(Exception):self.m.check()

    def test_canonical_source_hashes_match_release_constants(self):
        # Constants above are patched only for this fixture's two tiny sources.
        code=(ROOT/'scripts/infra/backup_https_prepare.py').read_text()
        self.assertIn(hashlib.sha256((ROOT/'scripts/infra/hdd_backup.py').read_bytes()).hexdigest(),code)
        self.assertIn(hashlib.sha256((ROOT/'scripts/infra/hdd_backup_schedule.py').read_bytes()).hexdigest(),code)


class Readiness(unittest.TestCase):
    def test_active_https_uses_tls_and_keeps_scheduler_receipt_contract(self):
        state=Mock();state.exists.return_value=True
        with patch.object(hdd,'HTTPS_STATE',state),patch.object(hdd,'https_ready',return_value={'login_http':200,'transport':'https'}) as probe,patch.object(hdd,'command',side_effect=AssertionError('plain HTTP called')):
            self.assertEqual('https',hdd.http_ready()['transport']);probe.assert_called_once()

    def test_https_failure_never_falls_back_to_http(self):
        state=Mock();state.exists.return_value=True
        with patch.object(hdd,'HTTPS_STATE',state),patch.object(hdd,'https_ready',side_effect=hdd.Refusal('bad TLS')),patch.object(hdd.time,'sleep'),patch.object(hdd,'command',side_effect=AssertionError('plain HTTP called')):
            with self.assertRaisesRegex(hdd.Refusal,'no HTTP fallback'):hdd.http_ready()

    def test_absent_https_preserves_initial_http_mode(self):
        state=Mock();state.exists.return_value=False;state.is_symlink.return_value=False
        with patch.object(hdd,'HTTPS_STATE',state),patch.object(hdd,'https_ready',side_effect=AssertionError('TLS unexpectedly called')),patch.object(hdd,'command',return_value='200'):
            self.assertEqual(200,hdd.http_ready()['login_http'])
