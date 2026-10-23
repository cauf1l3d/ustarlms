#!/usr/bin/env python3
"""Accept sanitized native privilege evidence, never infer missing checks as PASS."""
import json
from pathlib import Path
import sys

FLAGS = ('superuser', 'createrole', 'createdb', 'replication', 'bypassrls')
CHECKS = ('app_credential_cannot_auth_bootstrap', 'globals_legacy_POSTGRES_USER_private', 'privileged_membership_denied', 'xmldb_ddl', 'domain_writes', 'file_api', 'scheduled_task', 'pg_dump_app',
          'pg_restore_admin_set_app_role', 'schema_equal_after_restore',
          'data_equal_after_restore', 'globals_app_denied', 'globals_admin_private',
          'globals_not_uploaded')
DENIALS = ('create_role', 'create_database', 'server_file', 'server_program', 'role_password_catalog')


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def validate(value):
    require(isinstance(value, dict), 'native_evidence_shape')
    require(value.get('result') == 'DB_PRIVILEGES_NATIVE=PASS'
            and value.get('postgres_major') == 16, 'native_result_or_version')
    require(value.get('application_versions') == [2026102200, 2026102300, 2026102300],
            'actual_upgrade_and_repeat_required')
    app, admin = value.get('app'), value.get('admin')
    require(isinstance(app, dict) and isinstance(admin, dict), 'independent_roles_required')
    require(app.get('login_role') == app.get('effective_role') == 'ustar_app'
            and app.get('database_owner') == 1 and type(app.get('role_oid')) is int
            and app['role_oid'] > 10, 'actual_app_owner_session_required')
    require(all(type(app.get(k)) is int and app[k] == 0
                for k in (*FLAGS, 'dangerous_memberships')), 'cluster_privileges_retained')
    require(admin.get('role') == 'ustar_privilege_admin' and admin.get('superuser') == 1,
            'separate_admin_required')
    member = value.get('membership_refusal')
    require(isinstance(member, dict) and member.get('login_role') == member.get('effective_role') == 'ustar_app'
            and member.get('database_owner') == 1 and member.get('role_oid') == app['role_oid'] and type(member.get('dangerous_memberships')) is int
            and member['dangerous_memberships'] > 0
            and all(type(member.get(k)) is int and member[k] == 0 for k in FLAGS),
            'actual_privileged_membership_negative_control_required')
    handoff = value.get('role_handoff')
    require(isinstance(handoff, dict) and handoff.get('bootstrap_role') == 'ustar_fixture'
            and handoff.get('bootstrap_oid') == 10 and handoff.get('bootstrap_superuser') == 1
            and type(handoff.get('public_before')) is int and handoff['public_before'] > 0
            and handoff.get('public_app_after') == handoff['public_before']
            and handoff.get('public_bootstrap_after') == 0 and handoff.get('app_system_catalogs') == 0,
            'bootstrap_and_scoped_ownership_handoff_required')
    checks = value.get('checks')
    require(isinstance(checks, dict) and all(checks.get(k) is True for k in CHECKS),
            'incomplete_native_operation_proof')
    denied = value.get('denied_sqlstates')
    require(isinstance(denied, dict) and set(denied) == set(DENIALS)
            and all(state == '42501' for state in denied.values()),
            'permission_denial_not_other_failure_required')
    require(value.get('external_provider_called') is False, 'provider_isolation_required')
    return value


def main():
    path = Path(sys.argv[1]) if len(sys.argv) == 2 else Path(__file__).resolve().parents[2] / 'artifacts/db-privileges-native.json'
    validate(json.loads(path.read_text()))
    print('DB_PRIVILEGE_EVIDENCE=PASS')


if __name__ == '__main__': main()
