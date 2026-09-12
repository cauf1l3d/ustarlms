"""Linux descriptor-relative, bounded access to published context text only."""
import os
from pathlib import Path, PurePosixPath
import stat

ALLOWED = frozenset({'.md', '.txt', '.json', '.yaml', '.yml'})
MAX_BYTES = 512 * 1024
MAX_TOTAL = 2 * 1024 * 1024
MAX_FILES = 256


class ContextDenied(ValueError):
    pass


class ContextReader:
    def __init__(self, root):
        self.root = Path(root)

    @staticmethod
    def parts(path):
        if not isinstance(path, str) or not path or any(c in path for c in ('\\', '\x00', ':')):
            raise ContextDenied('invalid_path')
        parts = path.split('/')
        if parts[0] != 'context' or len(parts) < 2 or any(p in ('', '.', '..') for p in parts):
            raise ContextDenied('outside_context')
        if any(p.startswith('.') for p in parts[1:]) or PurePosixPath(path).suffix.lower() not in ALLOWED:
            raise ContextDenied('unsupported_type')
        return parts[1:]

    def read(self, path):
        parts = self.parts(path)
        # Never resolve a user-supplied symlink before the boundary check. Open
        # every component relative to held directory descriptors, including root.
        fds = []
        try:
            fd = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            fds.append(fd)
            fd = os.open('context', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            fds.append(fd)
            for part in parts[:-1]:
                fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                fds.append(fd)
            fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
            fds.append(fd)
            before = os.fstat(fd)
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1:
                raise ContextDenied('not_regular_file')
            if before.st_size > MAX_BYTES:
                raise ContextDenied('file_too_large')
            chunks, remaining = [], MAX_BYTES + 1
            while remaining:
                chunk = os.read(fd, min(65536, remaining))
                if not chunk:
                    break
                chunks.append(chunk)
                remaining -= len(chunk)
            raw = b''.join(chunks)
            after = os.fstat(fd)
            if len(raw) > MAX_BYTES:
                raise ContextDenied('file_too_large')
            if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
                raise ContextDenied('file_changed')
            if b'\x00' in raw:
                raise ContextDenied('non_text')
            return raw.decode('utf-8')
        except (OSError, UnicodeError) as exc:
            # No filesystem path, OS detail or file contents in the error.
            raise ContextDenied('unavailable_or_non_text') from exc
        finally:
            for fd in reversed(fds):
                os.close(fd)

    def paths(self, folder=''):
        base = self.root / 'context'
        if base.is_symlink():
            return
        count = 0
        for parent, dirs, files in os.walk(base, followlinks=False):
            dirs[:] = sorted(d for d in dirs if not d.startswith('.') and not (Path(parent)/d).is_symlink())
            for name in sorted(files):
                p = Path(parent) / name
                rel = p.relative_to(base).as_posix()
                if folder and not rel.startswith(folder + '/'):
                    continue
                if p.is_symlink() or p.suffix.lower() not in ALLOWED or name.startswith('.'):
                    continue
                yield 'context/' + rel
                count += 1
                if count >= MAX_FILES:
                    return

    def collect(self, folder=''):
        result, total = {}, 0
        for path in self.paths(folder):
            try:
                content = self.read(path)
            except ContextDenied:
                continue
            total += len(content.encode('utf-8'))
            if total > MAX_TOTAL:
                break
            result[path] = content
        return result
