#!/usr/bin/env python3
"""Direct2000→2200 application/cron transaction, with a durable public-write boundary."""
import base64
import copy
import json
import os
from pathlib import Path
import uuid
from connect_con02_contract import (private_failure, SERVICES, Refusal, require, sha, verify_database,
                                    https_snapshot, require_https_unchanged)
from connect_con02_state import capture, descriptor, sync

CRON_BEFORE_SHA = 'ae5fa1b075ff5f97300f5d5675d51367b95d821e0acf73c7c4458200af7e9751'
CRON_LINE = b"VERSIONS = {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}"
VERSIONS = {'local_ustar': '2026102200', 'theme_ustar': '2026100202'}


def patch_cron(raw, installation):
    require(sha(raw) == CRON_BEFORE_SHA and raw.count(CRON_LINE) == 1, 'cron_source_changed')
    require(isinstance(installation, dict) and installation.get('schema') == 1
            and installation.get('script_sha256') == CRON_BEFORE_SHA
            and installation.get('versions') == {'local_ustar': '2026102000', 'theme_ustar': '2026100202'},
            'cron_installation_changed')
    result = raw.replace(CRON_LINE, CRON_LINE.replace(b'2026102000', b'2026102200'))
    compile(result, '<reviewed-CON02-cron>', 'exec')
    return result, {**installation, 'versions': VERSIONS, 'script_sha256': sha(result)}


def service_snapshot(host):
    return {n: {'active': host.service_active(n), 'enabled': host.service_enabled(n)} for n in SERVICES}


def stop_workers(host):
    # One consumer; no new receiver is started until the old receiver is confirmed stopped.
    for unit in SERVICES:
        host.service('stop', unit)
        require(not host.service_active(unit), 'worker_stop_not_confirmed')


def restore_workers(host, states, *, profile='v1'):
    host.probe(profile)
    for unit in reversed(SERVICES):
        previous = states[unit]
        if host.service_enabled(unit) != previous['enabled']:
            host.service('enable' if previous['enabled'] else 'disable', unit)
        if previous['active']:
            host.service('start', unit)
        elif host.service_active(unit): host.service('stop', unit)
        require(host.service_active(unit) == previous['active'] and host.service_enabled(unit) == previous['enabled'],
                'worker_restore_not_confirmed')


class Application:
    def __init__(self, ctx, journal, locks):
        self.ctx, self.journal, self.locks = ctx, journal, locks
        self.d, self.host = ctx.engine, ctx.host

    def row(self):
        s = self.journal.load(); require(s is not None and s.get('app') is not None, 'application_transaction_required')
        return s['app']

    def bind(self, row):
        run = self.journal.folder(row)
        self.d.marker = run / 'primitive.json'
        marker=copy.deepcopy(row['primitive'])
        if hasattr(self.host,'bind_identities'):self.host.bind_identities(marker['identities'])
        return marker

    def step(self, phase, **fields):
        row = self.journal.checkpoint('app', phase, **fields)
        if hasattr(self.ctx, 'progress'): self.ctx.progress(phase)
        return row

    def cron_images(self):
        root = self.ctx.root
        paths = {'cron_script': root / 'usr/local/sbin/USTAR_CRON_SETUP_20261005.py',
                 'cron_installation': root / 'var/lib/ustar-cron/installation.json',
                 'cron_schedule': root / 'etc/cron.d/ustar_moodle'}
        images = {n: capture(p) for n, p in paths.items()}
        require(all(i['kind'] == 'file' and i['metadata']['uid'] == self.ctx.uid
                    and not i['metadata']['mode'] & 0o022 for i in images.values()), 'unsafe_cron_control')
        require(images['cron_installation']['metadata']['mode'] == 0o600, 'unsafe_cron_installation')
        from cron_release_update import CRON_TEXT
        require(images['cron_schedule']['raw'] == CRON_TEXT, 'cron_schedule_changed')
        installation = json.loads(images['cron_installation']['raw'])
        new, new_install = patch_cron(images['cron_script']['raw'], installation)
        desired = copy.deepcopy(images)
        desired['cron_script']['raw'] = new
        desired['cron_installation']['raw'] = (json.dumps(new_install, sort_keys=True) + '\n').encode()
        return images, desired

    def verify(self, row, *, target):
        self.bind(row)
        self.host.source(target=target)
        verify_database(self.host.database(row['prefix']), row['database'], self.ctx.bundle.reference, target=target)
        self.host.controls(self.journal.load())
        self.journal.guard('app')
        return row

    def upgrade(self):
        with self.locks.prebackup():
            previous = self.journal.load()
            if previous and previous.get('app'):
                row = previous['app']
                if row['phase'] == 'app_ready':
                    with self.locks.cold():
                        self.verify(row, target=True)
                        https_snapshot(self.ctx)
                        return {'phase': 'app_ready', 'verify_only': True}
                if row['phase'] != 'baseline_restored':
                    self.journal.guard('app');self.host.controls(previous)
                    self.step(row['phase'],https=https_snapshot(self.ctx))
                    result = self._recover()
                    return {**result, 'recovered_previous_run': True, 'new_upgrade_started': False}
            prefix = self.host.prefix()
            baseline = self.host.database(prefix)
            verify_database(baseline, baseline, self.ctx.bundle.reference, target=False)
            self.host.source(target=False)
            controls = self.host.controls(None)
            owner = https_snapshot(self.ctx)
            preflight = self.host.preflight()
            require(all(r['State']['Running'] for r in self.host.inspect().values()), 'production_not_running')
            cron, desired_cron = self.cron_images()
            generation = uuid.uuid4().hex
            primitive = {'kind': 'con02_application_primitives', 'run_directory': str(self.d.base / ('app-' + generation)),
                'roots': preflight['roots'], 'identities': preflight['identities'], 'prefix': prefix,
                'web_root': str(self.d.web), 'live_trees_modified': False, 'public_released': False}
            images = {**controls['files'], **cron}
            row = self.journal.begin('app', {'files': images, 'database': baseline, 'prefix': prefix,
                'controls': {n: descriptor(i) for n, i in controls['files'].items()}, 'fence': controls['fence'],
                'https': owner, 'restart_policies': preflight['restart_policies'], 'primitive': primitive,
                'public_released': False, 'switch_started': False, 'live_trees_modified': False,
                'service_states': service_snapshot(self.host)},
                {'files': {**controls['files'], **desired_cron}}, generation=generation)
            marker = self.bind(row)
            try:
                # The accepted child owns the backup lock itself. Only the first
                # four deployment locks are held at this point.
                receipt = self.host.fresh_backup(marker)
                row = self.step('fresh_backup_complete', fresh_hdd_backup=receipt,
                                service_states=service_snapshot(self.host))
                with self.locks.cold():
                    self.verify(row, target=False); require_https_unchanged(self.ctx, owner)
                    self.step('workers_stopped', switch_started=True)
                    stop_workers(self.host)
                    self.host.policy('no'); self.host.stop_web(clean=True)
                    frozen = self.host.database(prefix)
                    verify_database(frozen, baseline, self.ctx.bundle.reference, target=False)
                    require(frozen['other_clients'] == 0, 'other_database_clients')
                    self.host.stop(clean=True)
                    require(not any(r['State']['Running'] for r in self.host.inspect().values()), 'cold_stop_not_confirmed')
                    self.host.capacity(cold=True)
                    require(self.d.tx.roots(self.d) == marker['roots'], 'snapshot_roots_changed')
                    digest = self.d.tx.paired_copy(self.d, marker)
                    marker['baseline_manifest_sha256'] = digest
                    self.step('pair_complete', primitive=marker)
                    before = self.d.tx.inventory(self.d, self.d.p.CODE)
                    # Persist potential mutation BEFORE calling the old pure primitive.
                    self.step('source_activated', live_trees_modified=True)
                    self.d.addon.activate(self.d, marker)
                    self.preserve_metadata(before)
                    after = self.d.tx.inventory(self.d, self.d.p.CODE)
                    scopes = [str((self.d.web / s).relative_to(self.d.p.CODE)) for s in ('local/ustar', 'theme/ustar')]
                    outside = lambda k: not any(k == s or k.startswith(s + '/') for s in scopes)
                    require({k: v for k, v in before.items() if outside(k)}
                            == {k: v for k, v in after.items() if outside(k)}, 'unrelated_code_changed')
                    self.d.tx.add_maintenance(self.d); self.host.start()
                    schema = None
                    for number in (1, 2):
                        self.step('upgrade_' + str(number))
                        self.host.cli_upgrade()
                        db = self.host.database(prefix)
                        verify_database(db, baseline, self.ctx.bundle.reference, target=True)
                        current = (db['schema'], db['indexes'])
                        require(schema is None or schema == current, 'repeat_schema_drift'); schema = current
                        require(self.d.p.read_file(self.d.tx.maintenance_path(self.d), 4096) == self.d.tx.MAINTENANCE,
                                'maintenance_changed')
                    self.d.tx.verify_tree(self.d, self.d.p.CODE, after)
                    self.host.references(prefix)
                    self.journal.put('app', 'cron_script', 'after')
                    self.journal.put('app', 'cron_installation', 'after')
                    self.step('cron_written')
                    require_https_unchanged(self.ctx, owner)
                    self.journal.guard('app')
                    # After this durable point, automatic DB rollback is forbidden,
                    # even if the process dies before removing maintenance.
                    self.step('public_release_committed', public_released=True)
                    self.d.tx.remove_maintenance(self.d)
                    return self._finish()
            except BaseException:
                private_failure(self.ctx)
                try: return {**self._recover(), 'upgrade_succeeded': False}
                except BaseException:
                    private_failure(self.ctx)
                    self.step('recovery_required')
                    return {'phase': 'recovery_required', 'public_released': self.row()['public_released']}

    def preserve_metadata(self, before):
        for name, prior in before.items():
            path = self.d.p.CODE / name
            if not path.exists(): continue
            # activate() already retains uid/gid/mode. Extended metadata is reapplied
            # to existing paths, including ACLs, and then checked by inventory.
            wanted = prior.get('xattrs', {})
            for key in os.listxattr(path):
                if key not in wanted: os.removexattr(path, key)
            for key, value in wanted.items():
                os.setxattr(path, key, base64.b64decode(value, validate=True))
            require({key:base64.b64encode(os.getxattr(path,key)).decode() for key in os.listxattr(path)}
                    == wanted, 'activated_metadata_changed')

    def _finish(self):
        row = self.row()
        self.verify(row, target=True)
        require_https_unchanged(self.ctx, row['https'])
        guard = self.d.tx.maintenance_path(self.d)
        if guard.exists():
            require(self.d.p.read_file(guard, 4096) == self.d.tx.MAINTENANCE, 'maintenance_changed')
            self.d.tx.remove_maintenance(self.d)
        require(self.host.login() == 200, 'updated_login_failed')
        self.host.restore_policies(row['restart_policies'])
        restore_workers(self.host, row['service_states'])
        self.step('app_ready')
        return {'phase': 'app_ready', 'local_ustar': '2026102200', 'ack_enabled': False}

    def _recover(self):
        row = self.row()
        self.bind(row)
        require(not (row['public_released'] and row.get('restore_started')), 'explicit_restore_resume_required')
        if row['phase'] == 'baseline_restored':
            self.verify(row, target=False); return {'phase': 'baseline_restored'}
        if not row['switch_started']:
            # Producer recovery also owns its own backup lock.
            self.host.recover_backup()
            with self.locks.cold():
                self.verify(row, target=False)
                require(all(r['State']['Running'] for r in self.host.inspect().values()), 'backup_recovery_pending')
                self.step('baseline_restored')
                return {'phase': 'baseline_restored'}
        with self.locks.cold():
            self.journal.guard('app'); self.host.controls(self.journal.load())
            if row['public_released']:
                # Do not stop/copy back PostgreSQL, source, queue or cursors here.
                self.host.start()
                return self._finish()
            return self._restore_cold(row)

    def _restore_cold(self, row):
        marker = self.bind(row)
        self.journal.guard('app'); require_https_unchanged(self.ctx, row['https'])
        stop_workers(self.host); self.host.policy('no'); self.host.stop()
        require(not any(r['State']['Running'] for r in self.host.inspect().values()), 'restore_stop_not_confirmed')
        require(self.d.tx.roots(self.d) == marker['roots'], 'restore_roots_changed')
        if row['live_trees_modified']:
            pair = self.d.tx.read_pair(self.d, marker)
            self.host.restore_capacity(pair)
            self.step('recovery_required',restore_started=True)
            stash = self.journal.folder(row) / ('retained-before-restore-' + uuid.uuid4().hex)
            stash.mkdir(mode=0o700)
            # config.php and the private include are unchanged by an app upgrade.
            # Keep them at their live paths even if physical recovery is killed.
            self.restore_code(row,pair,stash,marker)
            for name, live in self.d.trees.items():
                if name=='code':continue
                self.d.u.move_children(self.d.cold, live, stash / name, marker['roots'][name][:2])
            for name, live in self.d.trees.items():
                if name=='code':continue
                self.d.u.copy_contents(self.d.cold, self.journal.folder(row) / 'baseline' / name, live, pair['trees'][name])
                self.d.tx.verify_tree(self.d, live, pair['trees'][name])
        # Matching cron/control images are restored before any public release.
        for name in row['files']: self.journal.put('app', name, 'before')
        guard = self.d.tx.maintenance_path(self.d)
        if not guard.exists(): self.d.tx.add_maintenance(self.d)
        self.host.start(); self.verify(row, target=False); self.host.references(row['prefix'])
        self.d.tx.remove_maintenance(self.d)
        require(self.host.login() == 200, 'restored_login_failed')
        self.host.restore_policies(row['restart_policies'])
        restore_workers(self.host, row['service_states'])
        self.step('baseline_restored', live_trees_modified=False,restore_started=False)
        return {'phase': 'baseline_restored'}

    def restore_code(self,row,pair,stash,marker):
        live=self.d.trees['code'];expected=pair['trees']['code']
        protected={'config.php','.ustar-connect'}
        wanted=lambda tree:{k:v for k,v in tree.items() if k.split('/',1)[0] in protected}
        require(wanted(self.d.tx.inventory(self.d,live))==wanted(expected), 'recovery_owned_config_drift')
        staging=self.journal.folder(row)/('restore-code-'+uuid.uuid4().hex)
        staging.mkdir(mode=0o700)
        self.d.u.copy_contents(self.d.cold,self.journal.folder(row)/'baseline/code',staging,expected)
        self.d.tx.verify_tree(self.d,staging,expected)
        retained=stash/'code';retained.mkdir(mode=0o700)
        for child in sorted(live.iterdir()):
            if child.name in protected:continue
            require(self.d.tx.root_id(live)==marker['roots']['code'],'restore_roots_changed')
            os.rename(child,retained/child.name)
        for child in sorted(staging.iterdir()):
            if child.name in protected:continue
            require(self.d.tx.root_id(live)==marker['roots']['code'],'restore_roots_changed')
            os.rename(child,live/child.name)
        for path in (retained,staging,live):self.d.cold.sync_directory(path)
        self.d.tx.verify_tree(self.d,live,expected)

    def recover(self):
        with self.locks.prebackup():
            # A new operation may follow a legitimate renewal by the HTTPS owner.
            # Freeze its verified state afresh; never rewrite any TLS-owned file.
            self.journal.guard('app');self.host.controls(self.journal.load())
            owner = https_snapshot(self.ctx)
            self.step(self.row()['phase'], https=owner)
            return self._recover()

    def restore(self, *, discard=False, outage=False):
        row = self.row()
        require(outage and (not row['public_released'] or discard), 'restore_requires_explicit_outage_and_discard')
        with self.locks.prebackup():
            require(not self.journal.load().get('runtime') or self.journal.load()['runtime']['phase'] == 'runtime_disabled',
                    'disable_ack_before_application_restore')
            self.journal.guard('app');self.host.controls(self.journal.load())
            self.step(row['phase'], https=https_snapshot(self.ctx))
            with self.locks.cold(): return self._restore_cold(self.row())
