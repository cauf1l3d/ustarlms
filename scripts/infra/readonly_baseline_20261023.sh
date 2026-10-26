#!/usr/bin/env bash
# INF-01 metadata only. Run as aduk after a separate successful sudo -v.
# No Moodle bootstrap, writes to application/DB, service actions or backup creation.
set -u
if ! sudo -n true; then
    exit 1
fi
date -u '+MEASURED_AT_UTC=%Y-%m-%dT%H:%M:%SZ'
hostname
uname -sr
df -hT / /opt/ustar/data/moodle

sudo -n timeout --kill-after=3s 40s docker exec -i ustar_postgres sh -c \
  'exec psql -X -q -P pager=off -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' <<'SQL'
BEGIN READ ONLY;
SET LOCAL statement_timeout = '12s';
SET LOCAL lock_timeout = '2s';
SET LOCAL idle_in_transaction_session_timeout = '20s';
SET LOCAL search_path = pg_catalog;
SHOW transaction_read_only;
SELECT current_database() AS database, current_user AS probe_role,
       current_setting('server_version') AS postgres, pg_conf_load_time();
SELECT rolname, rolcanlogin, rolsuper, rolcreaterole, rolcreatedb,
       rolreplication, rolbypassrls, rolinherit
FROM pg_catalog.pg_roles WHERE rolname !~ '^pg_' ORDER BY rolname;
SELECT member::regrole AS member, roleid::regrole AS granted_role, admin_option
FROM pg_catalog.pg_auth_members ORDER BY 1, 2;
SELECT datname, pg_get_userbyid(datdba) AS owner, datacl
FROM pg_catalog.pg_database WHERE datname = current_database();
SELECT nspname, pg_get_userbyid(nspowner) AS owner, nspacl
FROM pg_catalog.pg_namespace WHERE nspname = 'public';
SELECT c.relkind, pg_get_userbyid(c.relowner) AS owner, count(*) AS objects
FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' GROUP BY c.relkind, c.relowner ORDER BY 1, 2;
SELECT extname, pg_get_userbyid(extowner) AS owner FROM pg_catalog.pg_extension;
SELECT pg_get_userbyid(defaclrole) AS owner, defaclnamespace::regnamespace AS schema,
       defaclobjtype, defaclacl FROM pg_catalog.pg_default_acl ORDER BY 1, 2, 3;
SELECT usename, backend_type, state, count(*) AS connections
FROM pg_catalog.pg_stat_activity WHERE datname=current_database()
GROUP BY usename, backend_type, state ORDER BY 1, 2, 3;
SELECT rolsuper AS probe_is_superuser FROM pg_catalog.pg_roles
WHERE rolname=current_user \gset
\if :probe_is_superuser
SELECT rule_number, line_number, type, database, user_name, address, netmask,
       auth_method, (error IS NOT NULL) AS parse_error
FROM pg_catalog.pg_hba_file_rules ORDER BY rule_number NULLS LAST, file_name, line_number;
\else
\echo HBA_RULES_NOT_READ__PROBE_ROLE_IS_NOT_SUPERUSER
\endif
SELECT plugin, value FROM public.mdl_config_plugins
WHERE name='version' AND plugin IN ('local_ustar','theme_ustar') ORDER BY plugin;
ROLLBACK;
SQL

sudo -n timeout --kill-after=3s 12s /usr/bin/python3 -I -B - <<'PY'
import json, re, stat
from pathlib import Path
p = Path('/opt/ustar/data/moodle/public/config.php')
for path in [*reversed(p.parents), p]:
    try:
        s = path.lstat()
        print(json.dumps({'host_path': str(path), 'uid': s.st_uid, 'gid': s.st_gid,
                          'mode': oct(stat.S_IMODE(s.st_mode)), 'symlink': path.is_symlink()}))
    except OSError as e:
        print(json.dumps({'host_path': str(path), 'error_type': type(e).__name__}))
try:
    s = p.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_size > 262144:
        raise ValueError('config_type_or_size')
    with p.open('rb') as f:
        raw = f.read(262145).decode('utf-8')
    if len(raw.encode('utf-8')) > 262144:
        raise ValueError('config_size')
    values = {}
    for key in ('dbuser', 'dbname', 'prefix'):
        matches = re.findall(r'(?m)^[ \t]*\$CFG->' + key +
                             r'\s*=\s*([\'\"])([A-Za-z0-9_.-]{1,63})\1\s*;', raw)
        values[key] = matches[0][1] if len(matches) == 1 else 'not_one_supported_literal'
    print(json.dumps({'config_static_literals_only_not_effective_runtime': values}))
except (OSError, ValueError, UnicodeError) as e:
    print(json.dumps({'config_static_read_error_type': type(e).__name__}))
PY

sudo -n timeout --kill-after=3s 15s docker exec -i -u www-data ustar_moodle sh <<'SH'
id
for p in /var/www/html /var/www/html/config.php /var/www/html/public /var/www/html/public/config.php \
  /var/www/html/public/version.php /var/www/html/admin /var/www/html/public/lib \
  /var/www/html/public/local /var/www/html/public/local/ustar \
  /var/www/html/public/local/ustar/version.php /var/www/html/public/theme \
  /var/www/html/public/theme/ustar /var/www/html/public/theme/ustar/version.php \
  /var/www/moodledata /var/www/moodledata/cache /var/www/moodledata/localcache \
  /var/www/moodledata/sessions /var/www/moodledata/temp
do
    if [ -e "$p" ]; then
        stat -Lc 'RUNTIME_PATH=%n uid=%u gid=%g mode=%a' "$p"
        if [ -w "$p" ]; then printf 'WWW_DATA_WRITABLE=yes\n'; else printf 'WWW_DATA_WRITABLE=no\n'; fi
    else
        printf 'RUNTIME_PATH=%s missing_or_inaccessible\n' "$p"
    fi
done
SH

sudo -n timeout --kill-after=3s 10s docker inspect --format \
  '{{.Name}} id={{.Id}} image={{.Image}} state={{.State.Status}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} health={{with index .State "Health"}}{{.Status}}{{else}}not_configured{{end}} log_driver={{.HostConfig.LogConfig.Type}} {{with .HostConfig.LogConfig.Config}}max_size={{index . "max-size"}} max_file={{index . "max-file"}}{{else}}log_limits_not_configured{{end}} log_path={{.LogPath}} readonly_rootfs={{.HostConfig.ReadonlyRootfs}} userns={{.HostConfig.UsernsMode}}' \
  ustar_moodle ustar_postgres
sudo -n timeout --kill-after=3s 10s docker inspect --format \
  '{{.Name}} mounts={{json .Mounts}}' ustar_moodle ustar_postgres
sudo -n timeout --kill-after=3s 15s /usr/bin/python3 -I -B \
  /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
sudo -n timeout --kill-after=3s 15s /usr/bin/python3 -I -B \
  /usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py --status
