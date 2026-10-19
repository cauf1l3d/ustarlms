"""Owner-private local configuration/state paths; never silently repair permissions."""
import os
from pathlib import Path
import stat


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


def read_private(value, limit):
    path = checked_path(value)
    fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0,os.geteuid())
                or stat.S_IMODE(info.st_mode) not in (0o400,0o600) or info.st_nlink != 1):
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
