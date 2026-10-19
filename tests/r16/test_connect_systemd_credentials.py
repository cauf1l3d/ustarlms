"""Accept only root-owned read-only systemd credential ACLs, never arbitrary group-readable secrets."""
import importlib,os,stat,struct,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'connect-worker'))
def acl(perm,uid=999):
    return struct.pack('<I',2)+b''.join(struct.pack('<HHI',tag,p,who)for tag,p,who in
        [(1,perm,0xffffffff),(2,perm,uid),(4,0,0xffffffff),(16,perm,0xffffffff),(32,0,0xffffffff)])
class SystemdCredentialsTests(unittest.TestCase):
    def test_only_current_uid_acl_on_readonly_systemd_mount_is_accepted(self):
        module=importlib.import_module('ustar_connect.private_files')
        self.assertTrue(hasattr(module,'_systemd_credential'),'systemd credential classification missing')
        file=type('Info',(),{'st_uid':0,'st_gid':0,'st_mode':stat.S_IFREG|0o440,'st_nlink':1})()
        parent=type('Info',(),{'st_uid':0,'st_gid':0,'st_mode':stat.S_IFDIR|0o550})()
        path=Path('/run/credentials/ustar-connect-probe.service/probe.json')
        attrs=lambda p,*a,**kw:acl(5 if Path(p)==path.parent else 4)
        vfs=type('VFS',(),{'f_flag':os.ST_RDONLY})()
        with patch.object(module.os,'geteuid',return_value=999),patch.object(Path,'stat',return_value=parent),\
             patch.object(module.os,'getxattr',side_effect=attrs),patch.object(module.os,'statvfs',return_value=vfs):
            self.assertTrue(module._systemd_credential(path,file))
            self.assertFalse(module._systemd_credential(Path('/tmp/probe.service/secret'),file))
            parent.st_mode=stat.S_IFDIR|0o750;self.assertFalse(module._systemd_credential(path,file));parent.st_mode=stat.S_IFDIR|0o550
            vfs.f_flag=0;self.assertFalse(module._systemd_credential(path,file));vfs.f_flag=os.ST_RDONLY
            with patch.object(module.os,'getxattr',return_value=acl(4,uid=998)):
                self.assertFalse(module._systemd_credential(path,file))
            with patch.object(module.os,'getxattr',return_value=acl(4)+struct.pack('<HHI',2,4,1234)):
                self.assertFalse(module._systemd_credential(path,file))
    def test_ordinary_group_readable_secret_still_refuses(self):
        module=importlib.import_module('ustar_connect.private_files')
        with tempfile.TemporaryDirectory() as td:
            p=Path(td)/'secret';p.write_text('synthetic');p.chmod(0o440)
            with self.assertRaises(ValueError):module.read_private(p,100)
