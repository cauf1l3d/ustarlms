#!/usr/bin/env python3
"""Only the disposable existing PG16/Moodle fixture; no production entry point."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT=Path('/source')
if (Path.cwd()!=Path('/stage/moodle') or os.environ.get('USTAR_DB_PRIVILEGE_FIXTURE')!='1'
        or Path('/opt/ustar').exists() or not Path('/stage/db-privileges-state.json').is_file()):
    raise RuntimeError('disposable_privilege_fixture_required')
sys.path.insert(0,str(ROOT/'scripts/infra'))
import postgres_app_handoff as h

PRIVATE=Path('/stage/db-app-handoff-private')
PRIVATE.mkdir(mode=0o700)


def pg(query,*,role='ustar_privilege_admin',password=None,allow_failure=False):
    env=dict(os.environ)
    if password is not None:env['PGPASSWORD']=password
    result=subprocess.run(['psql','-X','-w','-qAt','-h','db','-U',role,'-d','ustar_stage1',
        '-v','ON_ERROR_STOP=1','-v','VERBOSITY=verbose'],input=query.encode(),capture_output=True,env=env,timeout=120)
    with (PRIVATE/'pg-private.log').open('ab') as log:
        log.write(result.stdout);log.write(result.stderr)
    if not allow_failure and result.returncode:raise RuntimeError('native_SQL_failed_private_log_only')
    return result


wire=json.loads(pg("BEGIN READ ONLY; SELECT json_build_object('oid',jsonb_typeof(to_jsonb(oid)),"
    "'bigint',jsonb_typeof(to_jsonb(oid::bigint))) FROM pg_roles WHERE rolname='ustar_fixture'; ROLLBACK;").stdout)
assert wire=={'oid':'string','bigint':'number'},'unexpected_PostgreSQL_OID_JSON_wire_type'
oids=json.loads(pg("BEGIN READ ONLY; SELECT json_object_agg(rolname,oid::bigint) FROM pg_roles "
    "WHERE rolname IN ('ustar_fixture','ustar_privilege_admin','ustar_app'); ROLLBACK;").stdout)
profile=h.Profile(database='ustar_stage1',bootstrap='ustar_fixture',admin='ustar_privilege_admin',
    app='ustar_app',bootstrap_oid=oids['ustar_fixture'],admin_oid=oids['ustar_privilege_admin'],
    app_oid=oids['ustar_app'],prefix='stage_')


def observe():return json.loads(pg(h.inventory_sql(profile)).stdout)


def bootstrap_inventory(ordinary):
    result=copy.deepcopy(ordinary)
    def acl(rows):
        if rows is None:return None
        return sorted([{**row,**{k:10 if row[k]==profile.app_oid else row[k]
                               for k in ('grantor','grantee')}} for row in rows],
                      key=lambda r:(r['grantor'],r['grantee'],r['privilege']))
    result['database'].update(owner=10,acl=acl(result['database']['acl']))
    for row in result['relations']:row.update(owner=10,acl=acl(row['acl']))
    h.validate_inventory(result,side='before',profile=profile)
    return result


# Mirror the observed separate, inaccessible monitoring definer without calling it.
pg("""CREATE ROLE ustar_console_stats NOLOGIN NOSUPERUSER NOCREATEROLE NOCREATEDB;
CREATE SCHEMA ustar_console AUTHORIZATION ustar_console_stats;
CREATE FUNCTION ustar_console.sample() RETURNS integer LANGUAGE sql SECURITY DEFINER
    SET search_path=pg_catalog,pg_temp AS 'SELECT 1';
ALTER FUNCTION ustar_console.sample() OWNER TO ustar_console_stats;
REVOKE ALL ON FUNCTION ustar_console.sample() FROM PUBLIC;
REVOKE ALL ON SCHEMA ustar_console FROM PUBLIC;""")
ordinary=observe();counts=h.validate_inventory(ordinary,side='after',profile=profile)
assert len(ordinary['routines'])==1
bootstrap=bootstrap_inventory(ordinary)
assert h.desired_inventory(bootstrap,profile=profile)==ordinary

# An exposed custom definer and even an unprivileged membership must fail closed.
pg('GRANT USAGE ON SCHEMA ustar_console TO ustar_app; GRANT EXECUTE ON FUNCTION ustar_console.sample() TO ustar_app;')
try:h.validate_inventory(observe(),side='after',profile=profile)
except h.Refusal:pass
else:raise AssertionError('exposed_definer_not_refused')
pg('REVOKE ALL ON SCHEMA ustar_console FROM ustar_app; REVOKE ALL ON FUNCTION ustar_console.sample() FROM ustar_app;')
assert observe()==ordinary
pg('CREATE ROLE ustar_delta_member NOLOGIN; GRANT ustar_delta_member TO ustar_app;')
try:h.validate_inventory(observe(),side='after',profile=profile)
except h.Refusal:pass
else:raise AssertionError('membership_not_refused')
pg('REVOKE ustar_delta_member FROM ustar_app; DROP ROLE ustar_delta_member;')
assert observe()==ordinary

# The actual generated transaction must reject stale inventory BEFORE committing.
pg('CREATE TABLE public.not_application(id integer);')
result=pg(h.ownership_sql(ordinary,bootstrap,profile=profile),allow_failure=True)
assert result.returncode and b'ownership_inventory_drift' in result.stderr
current=observe()
assert all(row['owner']==profile.app_oid for row in current['relations'] if row['name'].startswith('stage_'))
pg('DROP TABLE public.not_application;')
assert observe()==ordinary

# Exercise exactly the new transaction in both directions without a data restore.
schema_before=subprocess.check_output(['php','/source/tests/stage/schema_snapshot.php'],timeout=60)
pg(h.ownership_sql(ordinary,bootstrap,profile=profile))
assert observe()==bootstrap
denied=pg('BEGIN READ ONLY; SELECT count(*) FROM stage_user; ROLLBACK;',role='ustar_app',
    password='fixture-app-only-not-production-secret',allow_failure=True)
assert denied.returncode and b'42501' in denied.stderr
pg(h.ownership_sql(bootstrap,ordinary,profile=profile))
assert observe()==ordinary
schema_after=subprocess.check_output(['php','/source/tests/stage/schema_snapshot.php'],timeout=60)
assert schema_before==schema_after
subprocess.run(['php','/source/tests/stage/db_privileges_probe.php','verify'],check=True,timeout=60)

# PHP itself must read the exact special-character password produced by the patch.
config=b"<?php\n$CFG=new stdClass();\n$CFG->dbtype='pgsql';\n$CFG->dbname='moodle';\n$CFG->prefix='mdl_';\n$CFG->dbuser='moodle';\n$CFG->dbpass='synthetic-old';\n"
patched=h.patch_config(config,"synthetic-'\\$x;\u00e9")
checks=b"\necho json_encode([$CFG->dbuser==='ustar_app', $CFG->dbpass===hex2bin('" \
    + "synthetic-'\\$x;\u00e9".encode().hex().encode()+b"')]);\n"
php=PRIVATE/'literal.php';php.write_bytes(patched+checks);php.chmod(0o600)
subprocess.run(['php','-l',str(php)],check=True,stdout=subprocess.DEVNULL)
assert json.loads(subprocess.check_output(['php',str(php)]))==[True,True]

major=int(pg("BEGIN READ ONLY; SELECT current_setting('server_version_num')::int/10000; ROLLBACK;").stdout)
assert major==16
evidence={'result':'DB_APP_HANDOFF_NATIVE=PASS','postgres_major':major,'objects':counts,
    'OID_JSON_wire_types':wire,
    'checks':dict.fromkeys(['generated_SQL_roundtrip','exact_inventory_drift_refused','inaccessible_monitor_preserved',
        'exposed_definer_refused','membership_refused','schema_equal','selected_business_data_retained',
        'real_PHP_special_character_password','app_access_denied_while_bootstrap_owns_tables'],True),
    'full_production_coordinator_executed':False,'business_database_restored':False,
    'external_provider_called':False,'production_access':False}
Path('/artifacts/db-app-handoff-native.json').write_text(json.dumps(evidence,sort_keys=True)+'\n')
print('DB_APP_HANDOFF_NATIVE=PASS')

# Same disposable fixture: validate the consolidated readonly survey SQL, no new stand.
import remaining_survey as survey
class SurveyRunner:
    def parsed(self,argv,*,raw,**kwargs):
        assert argv[:4]==['docker','exec','-i','ustar_postgres']
        query=raw.decode()
        assert query.startswith('BEGIN READ ONLY;') and query.endswith('ROLLBACK;')
        return json.loads(pg(query).stdout)
summary=survey.postgres_survey(SurveyRunner(),prefix='stage_')
assert summary['database_bytes']>0 and summary['stats']['numbackends']>=1
assert set(summary['tasks'])=={'stage_task_scheduled','stage_task_adhoc'}
expected=json.loads(pg("BEGIN READ ONLY; SELECT json_build_object('count',count(*),"
    "'enabled',count(*) FILTER(WHERE disabled=0)) FROM public.stage_task_scheduled; ROLLBACK;").stdout)
assert summary['tasks']['stage_task_scheduled']['count']==expected['count']
assert summary['tasks']['stage_task_scheduled']['enabled']==expected['enabled']
assert observe()==ordinary
Path('/artifacts/infra-remaining-survey-native.json').write_text(json.dumps({
    'result':'INFRA_REMAINING_SURVEY_NATIVE=PASS','read_only_transactions':True,
    'scheduled_task_count':expected['count'],'scheduled_enabled':expected['enabled'],
    'disabled_tasks_excluded':True,'original_ownership_inventory_retained':True,
    'production_access':False,'provider_called':False,'full_live_host_survey_executed':False},sort_keys=True)+'\n')
print('INFRA_REMAINING_SURVEY_NATIVE=PASS')
