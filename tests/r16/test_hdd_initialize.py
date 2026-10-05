"""Offline refusal/order tests; no real block device is modified."""
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from contextlib import ExitStack, redirect_stdout
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[2] / 'scripts/infra/hdd_initialize.py'
spec = importlib.util.spec_from_file_location('hdd_initialize', SOURCE)
h = importlib.util.module_from_spec(spec)
spec.loader.exec_module(h)
DISK = Path('/dev/sdb')
NODE = {'name': '/dev/sdb', 'type': 'disk', 'ro': False, 'size': h.CAPACITY,
        'log-sec': 512, 'serial': h.SERIAL, 'wwn': h.WWN, 'maj:min': '8:16',
        'mountpoints': [None], 'fstype': None}
TABLE = {'label': 'gpt', 'device': '/dev/sdb', 'unit': 'sectors', 'sectorsize': 512,
         'partitions': [{'node': '/dev/sdb1', 'type': h.LINUX, 'start': 2048,
                         'size': 976766976, 'name': h.LABEL}]}
PART = {'name': '/dev/sdb1', 'type': 'part', 'fstype': None,
        'size': TABLE['partitions'][0]['size'] * 512, 'maj:min': '8:17', 'mountpoints': [None]}


class HDDTests(unittest.TestCase):
    def preflight_context(self, stack, node):
        stack.enter_context(patch.object(h.os, 'geteuid', return_value=0))
        stack.enter_context(patch.object(h.shutil, 'which', return_value='/usr/bin/tool'))
        stack.enter_context(patch.object(h, 'DEVICE', types.SimpleNamespace(resolve=lambda **kw: DISK)))
        for name, value in [('tree', node), ('block_number', '8:16'), ('unused', None),
                            ('no_signatures', None), ('blank_edges', None)]:
            stack.enter_context(patch.object(h, name, return_value=value))
        return stack.enter_context(patch.object(h, 'run', return_value=types.SimpleNamespace(stdout=str(h.CAPACITY))))

    def test_preflight_pass_uses_only_read_commands(self):
        with ExitStack() as stack:
            run = self.preflight_context(stack, copy.deepcopy(NODE))
            self.assertEqual(h.preflight(), (DISK, '8:16'))
            self.assertEqual(run.call_args.args[0], ['blockdev', '--getsize64', '/dev/sdb'])

    def test_changed_identity_existing_data_and_readonly_refused(self):
        cases = [{'serial': 'another disk'}, {'wwn': '0xBAD'}, {'size': h.CAPACITY - 512},
                 {'log-sec': 4096}, {'type': 'part'}, {'ro': True}, {'children': [PART]},
                 {'fstype': 'ext4'}, {'name': '/dev/sda'}]
        for change in cases:
            with self.subTest(change=change), ExitStack() as stack:
                self.preflight_context(stack, {**NODE, **change})
                with self.assertRaises(h.Refusal):
                    h.initialize()
                h.run.assert_not_called()

    def test_missing_dependency_prevents_writes(self):
        with ExitStack() as stack:
            self.preflight_context(stack, NODE)
            h.shutil.which.return_value = None
            with self.assertRaisesRegex(h.Refusal, 'Missing dependency'):
                h.initialize()
            h.run.assert_not_called()

    def test_in_use_signature_nonzero_edges_prevent_partitioning(self):
        for function in ('unused', 'no_signatures', 'blank_edges'):
            with self.subTest(function=function), ExitStack() as stack:
                run = self.preflight_context(stack, NODE)
                with patch.object(h, function, side_effect=h.Refusal('unsafe')):
                    with self.assertRaises(h.Refusal):
                        h.initialize()
                self.assertFalse(any(c.args[0][0] in ('sfdisk', 'mkfs.ext4') for c in run.call_args_list))

    def test_existing_signatures_not_ignored(self):
        with patch.object(h, 'run', return_value=types.SimpleNamespace(stdout='{"signatures":[{"type":"ext4"}]}')):
            with self.assertRaisesRegex(h.Refusal, 'Existing signature'):
                h.no_signatures(DISK)

    def test_blank_edge_reads_are_real_and_do_not_modify_file(self):
        with tempfile.TemporaryDirectory() as d, patch.object(h, 'CAPACITY', 2 * 1048576):
            p = Path(d) / 'sparse-disk'
            with p.open('wb') as f:
                f.truncate(h.CAPACITY)
            h.blank_edges(p)
            with p.open('r+b') as f:
                f.seek(h.CAPACITY - 1)
                f.write(b'x')
            before = p.read_bytes()
            with self.assertRaises(h.Refusal):
                h.blank_edges(p)
            self.assertEqual(p.read_bytes(), before)

    def test_system_disk_is_rejected_before_external_probe(self):
        dev = os.stat('/').st_dev
        node = {**NODE, 'maj:min': f'{os.major(dev)}:{os.minor(dev)}'}
        with patch.object(h, 'run') as run:
            with self.assertRaisesRegex(h.Refusal, 'System disk'):
                h.unused(node)
            run.assert_not_called()

    def test_mounted_device_is_rejected(self):
        with patch.object(h, 'run') as run:
            with self.assertRaisesRegex(h.Refusal, 'mounted'):
                h.unused({**NODE, 'mountpoints': ['/srv/data']})
            run.assert_not_called()

    def test_holders_other_namespaces_and_raw_open_users_refused(self):
        real_path = Path
        for hazard in ('none', 'holder', 'namespace', 'open', 'probe-error'):
            with self.subTest(hazard=hazard), tempfile.TemporaryDirectory() as directory:
                base = real_path(directory)
                holders = base / 'sys' / 'sdb' / 'holders'
                holders.mkdir(parents=True)
                proc = base / 'proc'
                (proc / '123').mkdir(parents=True)
                (proc / 'swaps').write_text('Filename Type Size Used Priority\n')
                (proc / '123' / 'mountinfo').write_text('1 2 8:16 / /data rw - ext4 /dev/sdb rw\n'
                                                      if hazard == 'namespace' else '')
                if hazard == 'holder':
                    (holders / 'dm-0').touch()
                def path(value):
                    return {'/sys/class/block': base / 'sys', '/proc': proc,
                            '/proc/swaps': proc / 'swaps'}.get(str(value), real_path(value))
                response = types.SimpleNamespace(returncode=0 if hazard == 'open' else 1,
                                                 stdout='123' if hazard == 'open' else '',
                                                 stderr='error' if hazard == 'probe-error' else '')
                with patch.object(h, 'Path', side_effect=path), \
                        patch.object(h, 'block_number', return_value='8:16'), \
                        patch.object(h, 'run', return_value=response):
                    if hazard == 'none':
                        h.unused(NODE)
                    else:
                        with self.assertRaises(h.Refusal):
                            h.unused(NODE)

    def test_unexpected_partition_layouts_refused(self):
        self.assertEqual(h.verify_partition(DISK, TABLE), Path('/dev/sdb1'))
        cases = [{'label': 'dos'}, {'device': '/dev/sda'}, {'sectorsize': 4096}, {'partitions': []}]
        for change in cases:
            with self.subTest(change=change), self.assertRaises(h.Refusal):
                h.verify_partition(DISK, {**TABLE, **change})
        for change in ({'node': '/dev/sda1'}, {'start': 4096}, {'size': 1},
                       {'size': h.CAPACITY // 512}, {'type': 'bad'}, {'name': 'other'}):
            with self.subTest(change=change), self.assertRaises(h.Refusal):
                h.verify_partition(DISK, {**TABLE, 'partitions': [{**TABLE['partitions'][0], **change}]})

    def simulate(self, failure=None, kernel=None, probe_uuid='12345678-1234-1234-1234-123456789abc'):
        calls = []
        def run(argv, **kwargs):
            calls.append(argv)
            if failure and argv[0] == failure:
                raise h.Refusal('injected command failure')
            if argv[:2] == ['sfdisk', '--json']:
                out = json.dumps({'partitiontable': TABLE})
            elif argv[0] == 'blkid':
                out = f'TYPE=ext4\nLABEL=ustar-hdd\nUUID={probe_uuid}\n'
            else:
                out = ''
            return types.SimpleNamespace(stdout=out)
        with ExitStack() as stack:
            for name, value in [('preflight', (DISK, '8:16')), ('block_number', '8:16'),
                                ('tree', kernel or {**NODE, 'children': [PART]}),
                                ('unused', None), ('no_signatures', None)]:
                stack.enter_context(patch.object(h, name, return_value=value))
            stack.enter_context(patch.object(h, 'DEVICE', types.SimpleNamespace(resolve=lambda **kw: DISK)))
            stack.enter_context(patch.object(h.uuid, 'uuid4', return_value='12345678-1234-1234-1234-123456789abc'))
            stack.enter_context(patch.object(h, 'run', side_effect=run))
            stack.enter_context(redirect_stdout(io.StringIO()))
            try:
                return h.initialize(), calls
            except h.Refusal:
                return None, calls

    def test_format_only_after_verified_partition_and_return_uuid(self):
        result, calls = self.simulate()
        self.assertEqual(result['status'], 'FILESYSTEM_READY')
        self.assertFalse(result['mounted'])
        self.assertFalse(result['fstab_changed'])
        self.assertFalse(result['backups_scheduled'])
        mkfs = next(c for c in calls if c[0] == 'mkfs.ext4')
        self.assertEqual(mkfs[-1], '/dev/sdb1')
        self.assertNotIn('-F', mkfs)
        self.assertIn('--wipe=never', calls[0])
        self.assertNotIn('--force', calls[0])
        self.assertFalse(any(c[0] in ('mount', 'systemctl', 'docker', 'resize2fs') for c in calls))

    def test_partition_or_udev_failure_never_formats(self):
        for failure in ('sfdisk', 'udevadm'):
            with self.subTest(failure=failure):
                result, calls = self.simulate(failure=failure)
                self.assertIsNone(result)
                self.assertNotIn('mkfs.ext4', [c[0] for c in calls])

    def test_kernel_partition_mismatch_never_formats(self):
        for change in ({'name': '/dev/sda1'}, {'fstype': 'ext4'}, {'size': 12}, {'type': 'disk'}):
            with self.subTest(change=change):
                result, calls = self.simulate(kernel={**NODE, 'children': [{**PART, **change}]})
                self.assertIsNone(result)
                self.assertNotIn('mkfs.ext4', [c[0] for c in calls])

    def test_format_failure_has_no_retry_or_success(self):
        result, calls = self.simulate(failure='mkfs.ext4')
        self.assertIsNone(result)
        self.assertEqual(sum(c[0] == 'mkfs.ext4' for c in calls), 1)
        self.assertNotIn('blkid', [c[0] for c in calls])

    def test_uuid_mismatch_does_not_claim_success(self):
        self.assertIsNone(self.simulate(probe_uuid='wrong')[0])

    def test_check_does_not_open_lock_or_initialize(self):
        with patch.object(h, 'preflight', return_value=(DISK, '8:16')), \
                patch.object(h, 'initialize') as initialize, patch.object(h.os, 'open') as opened, \
                patch.object(h.os, 'umask'), redirect_stdout(io.StringIO()) as out:
            h.main(['--check'])
            initialize.assert_not_called()
            opened.assert_not_called()
            self.assertFalse(json.loads(out.getvalue())['writes'])


if __name__ == '__main__':
    unittest.main()
