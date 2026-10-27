"""Owner-private local configuration/state paths; never silently repair permissions."""
import os
from pathlib import Path
import stat
import struct


def checked_path(value):
    path = Path(value)
    if not path.is_absolute() or '..' in path.parts or any(p.is_symlink() for p in (path,*path.parents)):
        raise ValueError('unsafe_private_path')
    for parent in path.parents:
        info = parent.stat()
        if info.st_uid not in (0,os.geteuid()) or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
            raise ValueError('unsafe_private_ancestor')
    return path


def private_directory(value):
    path = checked_path(value)
    info = path.stat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
        raise ValueError('unsafe_state_directory')
    return path


def _systemd_credential(path, info):
    """systemd v254+ uses root-owned 0440 with a sole named-user ACL, not group access.

    Accept this only inside our fixed credential mounts, readonly, with exact root+current UID ACLs.
    Ordinary group-readable configuration remains forbidden.
    """
    try:
        if (path.parent.parent != Path('/run/credentials')
                or path.parent.name not in ('ustar-connect-receiver.service','ustar-connect-probe.service','ustar-connect-notify.service')
                or info.st_uid != 0 or info.st_gid != 0 or info.st_nlink != 1
                or not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o440):
            return False
        parent=path.parent.stat()
        if (parent.st_uid != 0 or parent.st_gid != 0 or not stat.S_ISDIR(parent.st_mode)
                or stat.S_IMODE(parent.st_mode) != 0o550
                or not os.statvfs(path.parent).f_flag & os.ST_RDONLY):
            return False
        def exact_acl(target, perm):
            raw=os.getxattr(target,'system.posix_acl_access',follow_symlinks=False)
            if len(raw) != 44 or struct.unpack_from('<I',raw)[0] != 2:return False
            entries=[struct.unpack_from('<HHI',raw,n)for n in range(4,44,8)]
            expected=[(1,perm,0xffffffff),(2,perm,os.geteuid()),(4,0,0xffffffff),
                      (16,perm,0xffffffff),(32,0,0xffffffff)]
            return entries==expected
        return exact_acl(path,4) and exact_acl(path.parent,5)
    except (OSError,ValueError,struct.error):
        return False


def read_private(value, limit):
    path = checked_path(value)
    fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0,os.geteuid())
                or (stat.S_IMODE(info.st_mode) not in (0o400,0o600) and not _systemd_credential(path,info))
                or info.st_nlink != 1):
            raise ValueError('unsafe_private_file')
        raw = os.read(fd,limit+1)
        if len(raw)>limit:
            raise ValueError('private_file_too_large')
        return raw.decode('utf-8')
    finally:
        os.close(fd)


def trusted_certificate(value):
    path = checked_path(value)
    info = path.stat()
    if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0,os.geteuid())
            or info.st_mode & 0o022 or info.st_nlink != 1):
        raise ValueError('unsafe_ca_file')
    return str(path)
