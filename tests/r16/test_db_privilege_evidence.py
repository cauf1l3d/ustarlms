"""Host acceptance must reject incomplete or privileged native evidence."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class Evidence(unittest.TestCase):
    def module(self):
        path = ROOT / 'tests/stage/db_privileges_verify.py'
        self.assertTrue(path.is_file(), 'native privilege evidence verifier is missing')
        spec = importlib.util.spec_from_file_location('db_privileges_verify', path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def evidence(self):
        return {
            'result': 'DB_PRIVILEGES_NATIVE=PASS', 'postgres_major': 16,
            'application_versions': [2026102200, 2026102300, 2026102300],
            'app': {'login_role': 'ustar_fixture', 'effective_role': 'ustar_fixture',
                    'database_owner': 1, 'dangerous_memberships': 0,
                    **dict.fromkeys(['superuser', 'createrole', 'createdb', 'replication', 'bypassrls'], 0)},
            'admin': {'role': 'ustar_privilege_admin', 'superuser': 1},
            'membership_refusal': {'login_role': 'ustar_fixture', 'effective_role': 'ustar_fixture',
                'database_owner': 1, 'dangerous_memberships': 1,
                **dict.fromkeys(['superuser', 'createrole', 'createdb', 'replication', 'bypassrls'], 0)},
            'checks': dict.fromkeys(['privileged_membership_denied', 'xmldb_ddl', 'domain_writes', 'file_api', 'scheduled_task',
                'pg_dump_app', 'pg_restore_admin_set_app_role', 'schema_equal_after_restore',
                'data_equal_after_restore', 'globals_app_denied', 'globals_admin_private',
                'globals_not_uploaded'], True),
            'denied_sqlstates': dict.fromkeys(['create_role', 'create_database', 'server_file',
                                             'server_program', 'role_password_catalog'], '42501'),
            'external_provider_called': False,
        }

    def test_accepts_complete_native_delta(self):
        value = self.evidence()
        self.assertEqual(value, self.module().validate(value))

    def test_rejects_every_elevated_flag_or_dangerous_membership(self):
        module = self.module()
        for key in ['superuser', 'createrole', 'createdb', 'replication', 'bypassrls', 'dangerous_memberships']:
            with self.subTest(key=key):
                value = self.evidence(); value['app'][key] = 1
                with self.assertRaises(ValueError): module.validate(value)

    def test_rejects_admin_masquerade_or_wrong_database_owner(self):
        module = self.module()
        for key, bad in [('login_role', 'ustar_privilege_admin'), ('effective_role', 'ustar_privilege_admin'),
                         ('database_owner', 0)]:
            value = self.evidence(); value['app'][key] = bad
            with self.assertRaises(ValueError): module.validate(value)

    def test_rejects_readonly_or_transport_failure_as_permission_denial(self):
        module = self.module()
        for bad in ['25006', '08006', None]:
            value = self.evidence(); value['denied_sqlstates']['create_role'] = bad
            with self.assertRaises(ValueError): module.validate(value)

    def test_requires_zero_flag_app_rejected_for_privileged_membership(self):
        module = self.module()
        for key, bad in [('dangerous_memberships', 0), ('superuser', 1),
                         ('login_role', 'ustar_privilege_admin')]:
            with self.subTest(key=key):
                value = self.evidence(); value['membership_refusal'][key] = bad
                with self.assertRaises(ValueError): module.validate(value)
        value = self.evidence(); del value['membership_refusal']
        with self.assertRaises(ValueError): module.validate(value)

    def test_rejects_missing_globals_restore_or_task_proof(self):
        module = self.module()
        for key in self.evidence()['checks']:
            value = self.evidence(); value['checks'][key] = False
            with self.assertRaises(ValueError): module.validate(value)

    def test_rejects_missing_upgrade_repeat_or_external_send(self):
        module = self.module()
        value = self.evidence(); value['application_versions'] = [2026102300, 2026102300]
        with self.assertRaises(ValueError): module.validate(value)
        value = self.evidence(); value['external_provider_called'] = True
        with self.assertRaises(ValueError): module.validate(value)


if __name__ == '__main__': unittest.main()
