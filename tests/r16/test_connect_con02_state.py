"""Real file images, metadata and flock; no Docker/SQL claims."""
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import unittest
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/infra'))
import connect_con02_contract as c


class State(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.ctx = c.Context(SimpleNamespace(reference={'tool_commit': 'a' * 40}), self.root, os.getuid(), None,
                             SimpleNamespace(base=self.root / 'state'))

    def module(self):
        self.assertIsNotNone(importlib.util.find_spec('connect_con02_state'), 'durable state interface')
        import connect_con02_state as s
        return s

    def setup_files(self, s):
        p = self.root / 'control'; p.write_bytes(b'private before'); p.chmod(0o600)
        before = {'files': {'control': s.capture(p)}, 'database': {'sentinel': 1}}
        after = {'files': {'control': {**before['files']['control'], 'raw': b'private after'}}}
        return p, before, after

    def test_durable_partial_pair(self):
        s = self.module(); p, before, after = self.setup_files(s)
        j = s.Journal(self.ctx); j.begin('app', before, after, generation='a' * 32)
        j.put('app', 'control', 'after')
        j = s.Journal(self.ctx); self.assertEqual('prepared', j.load()['app']['phase'])
        j.guard('app'); j.put('app', 'control', 'before')
        self.assertEqual(b'private before', p.read_bytes())
        for f in self.ctx.engine.base.rglob('*'):
            self.assertEqual(0o700 if f.is_dir() else 0o600, f.stat().st_mode & 0o777)

    def test_unknown_admin_drift(self):
        s = self.module(); p, before, after = self.setup_files(s)
        j = s.Journal(self.ctx); j.begin('app', before, after, generation='b' * 32)
        p.write_bytes(b'administrator edit')
        with self.assertRaises(c.Refusal): j.put('app', 'control', 'before')
        self.assertEqual(b'administrator edit', p.read_bytes())

    def test_unchanged_image_preserves_inode(self):
        s = self.module(); p,before,_ = self.setup_files(s)
        j=s.Journal(self.ctx);j.begin('app',before,{'files':before['files']},generation='e'*32)
        inode=p.stat().st_ino;j.put('app','control','after')
        self.assertEqual(inode,p.stat().st_ino)

    def test_interrupted_pointer_temporary_resumes_exact_saved_image(self):
        s=self.module()
        from unittest.mock import patch
        pointer=self.root/'current';pointer.symlink_to('/old-release')
        before={'files':{'pointer':s.capture(pointer)}}
        after={'files':{'pointer':{**before['files']['pointer'],'raw':b'/new-release'}}}
        j=s.Journal(self.ctx);j.begin('runtime',before,after,generation='f'*32)
        pid=os.fork()
        if pid==0:
            original=s.os.replace
            def crash(source,dest):
                if Path(dest)==pointer:os._exit(73)
                return original(source,dest)
            with patch.object(s.os,'replace',crash):j.put('runtime','pointer','after')
            os._exit(74)
        _,status=os.waitpid(pid,0);self.assertEqual(73,os.waitstatus_to_exitcode(status))
        j.put('runtime','pointer','after')
        self.assertEqual('/new-release',os.readlink(pointer))
        self.assertFalse(os.path.lexists(pointer.with_name('.con02-pointer-'+'f'*32)))

    def test_foreign_pointer_temporary_is_preserved_and_refused(self):
        s=self.module();pointer=self.root/'current';pointer.symlink_to('/old-release')
        before={'files':{'pointer':s.capture(pointer)}}
        after={'files':{'pointer':{**before['files']['pointer'],'raw':b'/new-release'}}}
        j=s.Journal(self.ctx);j.begin('runtime',before,after,generation='f'*32)
        temporary=pointer.with_name('.con02-pointer-'+'f'*32);temporary.symlink_to('/foreign')
        with self.assertRaises(c.Refusal):j.put('runtime','pointer','after')
        self.assertEqual('/foreign',os.readlink(temporary));self.assertEqual('/old-release',os.readlink(pointer))

    def test_metadata_uid33_root_gid_acl(self):
        s = self.module(); p, before, after = self.setup_files(s)
        if os.getuid() != 0:
            if os.getenv('USTAR_REQUIRE_CON02_OWNER') == '1': self.fail('root UID33 fixture required')
            self.skipTest('actual chown requires root')
        try: os.chown(p, 0, 33)
        except OSError:
            if os.getenv('USTAR_REQUIRE_CON02_OWNER') == '1': raise
            self.skipTest('container user namespace has no GID33 mapping')
        p.chmod(0o640)
        os.setxattr(p, 'user.con02', b'accepted')
        before['files']['control'] = s.capture(p)
        after['files']['control'] = {**before['files']['control'], 'raw': b'new'}
        j = s.Journal(self.ctx); j.begin('app', before, after, generation='c' * 32)
        j.put('app', 'control', 'after'); j.put('app', 'control', 'before')
        self.assertEqual(before['files']['control'], s.capture(p))

    def test_check_status_create_nothing(self):
        s = self.module(); j = s.Journal(self.ctx)
        self.assertIsNone(j.load()); self.assertEqual({'phase': 'uninitialized'}, j.public())
        with self.assertRaises(c.Refusal):
            with s.Locks(self.ctx).check(): pass
        self.assertEqual([], list(self.root.iterdir()))

    def lockfiles(self, s):
        locks = s.Locks(self.ctx)
        for path in locks.paths[1:]:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.touch(mode=0o600)
        return locks

    def test_backup_not_held_in_prebackup(self):
        s = self.module(); locks = self.lockfiles(s)
        import fcntl
        with locks.prebackup():
            with locks.paths[-2].open('rb') as fd:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with locks.cold():
                with locks.paths[-2].open('rb') as fd:
                    with self.assertRaises(BlockingIOError): fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_replaced_lock_inode(self):
        s = self.module(); locks = self.lockfiles(s)
        with self.assertRaises(c.Refusal):
            with locks.prebackup():
                p = locks.paths[1]; p.unlink(); p.touch(mode=0o600)

    def test_invalid_scope_and_public_secrets(self):
        s = self.module(); p, before, after = self.setup_files(s)
        j = s.Journal(self.ctx)
        with self.assertRaises(c.Refusal): j.begin('../app', before, after, generation='d' * 32)
        self.assertFalse(self.ctx.engine.base.exists())
        j.begin('runtime', before, after, generation='d' * 32)
        self.assertNotIn('private', str(j.public()))


if __name__ == '__main__': unittest.main()
