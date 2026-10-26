# INF-03 code permission inventory — 25Oct2026

DB handoff accepted; see infra_app_handoff_owner_20261025.json. Owner authorized
next file-hardening stage. No production permission changes prepared/applied yet.

Existing connect_con02_application.preserve_metadata preserves xattrs; installer
activation preserves metadata and live root identity; restore checks exact roots.
A blanket chown/chmod can invalidate old recovery or be reverted by future deploy.
Choose scoped ownership/mode changes with recorded before-images and explicit
compatibility for deploy/recovery after receiving current inventory. Considered
read-only bind remount/recreation: larger runtime and container-identity change,
not justified before inventory. Preserve data/cache write paths and existing
config root:33/0640. No changes to historical frozen controllers or journals.

Next owner probe reads metadata only; no PHP bootstrap, file contents, DB queries,
network/provider calls or chmod. Code tree only, no symlink following; same-device
walk, bounded externally to90s. Any errors/incomplete walk block implementation.
UID33 effective selected-path checks run inside the actual Moodle container.

```bash
sudo -v
sudo -n timeout --kill-after=3s 90s /usr/bin/python3 -I -B - <<'PY'
import os, stat, json
from collections import Counter
from pathlib import Path
root = Path('/opt/ustar/data/moodle/public')
if root.is_symlink() or not root.is_dir():
    raise SystemExit('unexpected_code_root')
for p in [Path('/opt'), Path('/opt/ustar'), Path('/opt/ustar/data'), root.parent, root, root/'config.php']:
    s = p.lstat()
    print(json.dumps({'path':str(p),'uid':s.st_uid,'gid':s.st_gid,'mode':oct(stat.S_IMODE(s.st_mode)),
                      'acl':[x for x in os.listxattr(p,follow_symlinks=False) if x.startswith('system.posix_acl_')]}))
counts=Counter(); flags=Counter(); device=root.stat().st_dev
errors=[]
for directory, dirs, files in os.walk(root, followlinks=False, onerror=lambda e: errors.append(e.errno)):
    for name in dirs[:]:
        p=Path(directory)/name
        if p.is_symlink() or p.lstat().st_dev != device:
            dirs.remove(name); flags['pruned_directories']+=1
    for p in [Path(directory)]+[Path(directory)/n for n in files]:
        s=p.lstat()
        kind='directory' if stat.S_ISDIR(s.st_mode) else 'file' if stat.S_ISREG(s.st_mode) else 'other'
        counts[(kind,s.st_uid,s.st_gid,oct(stat.S_IMODE(s.st_mode)))]+=1
        if s.st_nlink>1 and kind=='file': flags['hardlinked_files']+=1
        if any(x.startswith('system.posix_acl_') for x in os.listxattr(p,follow_symlinks=False)): flags['acl_entries']+=1
        if sum(counts.values())>200000: raise SystemExit('inventory_limit')
print(json.dumps({'profiles':[{'kind':k[0],'uid':k[1],'gid':k[2],'mode':k[3],'count':v} for k,v in sorted(counts.items())],
                  'flags':dict(flags),'walk_errors':errors,'complete':not errors}))
PY
sudo -n docker exec -u 33:33 ustar_moodle sh -c '
id
for p in /var/www/html /var/www/html/config.php /var/www/html/public /var/www/html/public/local/ustar /var/www/html/public/theme/ustar /var/www/moodledata; do
  r=no; w=no; x=no
  [ -r "$p" ] && r=yes
  [ -w "$p" ] && w=yes
  [ -x "$p" ] && x=yes
  printf "%s read=%s write=%s traverse_or_execute=%s\n" "$p" "$r" "$w" "$x"
done'
```
