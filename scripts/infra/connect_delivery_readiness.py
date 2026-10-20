#!/usr/bin/env python3
"""Read-only chat-link delivery contracts after accepted Academy HTTPS and cron repair.

No PHP bootstrap, TLS/Telegram request, polling, key read, file write or lifecycle
operation. This is preparation evidence, never authorization to deploy or activate.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import signal
import stat
import sys

import connect_readiness as readiness
from cron_release_update import CRON_TEXT
from production_patch_readiness import Refusal, require, read_file, command

BASELINE = '830d3703e2ffa2f9864900c0d0c58f60049cb0c2'
CRON_SHA = 'c5eabdceaa2f137eccd35087b03d473f61257aaeb77624f4855583ebaf353638'
HTTPS_SHA = '42550fb1ab769dbcad7b604fe5e0619f6c7ac69fa5cbac46c624924f6895aa6e'
CURRENT = {'local_ustar': 2026101905, 'theme_ustar': 2026100202}
TARGET = {'local_ustar': 2026102000, 'theme_ustar': 2026100202}
OWNED = {'config':'opt/ustar/data/moodle/public/config.php',
    'http':'etc/apache2/sites-available/ustar.conf',
    'tls':'etc/apache2/sites-available/zzzz-ustar-academy-tls-probe.conf',
    'health':'srv/ustar-academy-tls-probe/__ustar_tls_probe__/ready.html'}
HTTPS_UNITS = ('ustar-academy-cert-renew.service', 'ustar-academy-cert-renew.timer')


class Deadline(BaseException): pass


def sha(raw): return hashlib.sha256(raw).hexdigest()


def unique(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'invalid_control_json'); result[key] = value
    return result


def secure_read(root, relative, uid, private=False):
    path = root / relative
    for parent in (path.parent, *path.parent.parents):
        if not parent.is_relative_to(root): break
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == uid
                and not info.st_mode & 0o022, 'unsafe_control_input')
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_uid == uid and info.st_nlink == 1
            and not info.st_mode & (0o077 if private else 0o022), 'unsafe_control_input')
    return read_file(path, 2 * 1024 ** 2)


def control_json(root, relative, uid):
    try:
        value = json.loads(secure_read(root, relative, uid, True), object_pairs_hook=unique)
        require(isinstance(value, dict), 'invalid_control_json'); return value
    except (ValueError, UnicodeError): raise Refusal('invalid_control_json') from None


def https_contract(root, uid):
    relative = 'var/lib/ustar-academy-https/state.json'
    state = control_json(root, relative, uid)
    require(state.get('schema') == 1 and state.get('phase') == 'active', 'https_not_active')
    require(state.get('tool_sha') == HTTPS_SHA and sha(secure_read(root,
        'usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py', uid, True)) == HTTPS_SHA, 'https_tool_changed')
    for name, path in OWNED.items():
        if name == 'config':
            # Config ownership belongs to the accepted HTTPS capture. The
            # application tree can legitimately be UID33; never request chown
            # merely to satisfy a new read-only tool. Root-private state and
            # the exact accepted hash remain the authority, not a guessed UID.
            raw = read_file(root/path, 2 * 1024 ** 2)
            info = (root/path).lstat(); meta = state['files'][name]
            require((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
                    == (meta['uid'], meta['gid'], meta['mode']), 'https_config_metadata_changed')
        else: raw = secure_read(root, path, uid)
        require(sha(raw) == state['files'][name]['after'], 'https_owned_file_changed')
        if name == 'config':
            require(not re.search(rb'\$CFG\s*->\s*ustar_connect_[A-Za-z_]+\s*=', raw),
                    'connect_activation_already_configured')
    require(sha(secure_read(root, 'etc/apache2/ustar-console-observability.conf', uid))
            == state['include_sha'], 'https_include_changed')
    for name in HTTPS_UNITS:
        require(sha(secure_read(root, 'etc/systemd/system/'+name, uid)) == state['unit_hashes'][name],
                'https_unit_changed')
    for name, target in (('ustar.conf', 'http'), ('zzzz-ustar-academy-tls-probe.conf', 'tls')):
        link = root/'etc/apache2/sites-enabled'/name
        require(link.is_symlink() and link.resolve() == root/OWNED[target], 'https_site_link_changed')
    require(state == control_json(root, relative, uid), 'https_state_changed_during_check')
    return {'status':'measured','phase':'active','owned_hashes_match':True,
            'renewal_ownership_handoff_required':True,'effective_TLS_request_performed':False}


def cron_contract(root, uid, now):
    # Executable code follows the existing cron installer's integrity policy.
    # Installation state and receipts below still require private permissions.
    require(sha(secure_read(root, 'usr/local/sbin/USTAR_CRON_SETUP_20261005.py', uid))
            == CRON_SHA, 'cron_source_changed')
    require(secure_read(root, 'etc/cron.d/ustar_moodle', uid) == CRON_TEXT, 'cron_schedule_changed')
    state = control_json(root, 'var/lib/ustar-cron/installation.json', uid)
    require(state.get('script_sha256') == CRON_SHA and state.get('versions')
            == {k:str(v) for k,v in CURRENT.items()}, 'cron_installation_mismatch')
    receipt = control_json(root, 'var/lib/ustar-cron/last-run.json', uid)
    require(receipt.get('trigger') == 'scheduled' and type(receipt.get('cron_exit_code')) is int
            and receipt['cron_exit_code'] == 0 and receipt.get('log_truncated', False) is False,
            'cron_scheduled_success_missing')
    finished = datetime.fromisoformat(receipt['finished_utc'].replace('Z','+00:00'))
    require(finished.tzinfo is not None and -120 <= (now-finished).total_seconds() <= 600,
            'cron_success_not_fresh')
    after = receipt.get('after', {})
    require(after.get('ustar_failed') == 0 and after.get('new_failures', []) == [], 'cron_new_task_failure')
    # Historical overdue/failed counts are observations, not silently reset or
    # required to be zero. Their classification belongs to the canonical worker.
    return {'status':'measured','script_sha256':CRON_SHA,'versions':CURRENT,
            'last_scheduled_finished_utc':finished.astimezone(timezone.utc).isoformat(),
            'residual_overdue':after.get('scheduled_overdue_10m'),
            'residual_scheduled_failed':after.get('scheduled_failed'),
            'target_version_update_required':True}


def transport_contract(runner):
    template = '{"running":{{json .State.Running}},"ports":{{json .NetworkSettings.Ports}},"networks":{{json .NetworkSettings.Networks}}}'
    data = json.loads(runner(['docker','inspect','--format',template,'ustar_moodle'], timeout=15))
    require(data.get('running') is True, 'backend_not_running')
    bindings = data.get('ports', {}).get('80/tcp')
    require(isinstance(bindings, list) and bindings and all(b.get('HostIp') == '127.0.0.1'
            and b.get('HostPort') == '8082' for b in bindings), 'backend_binding_changed')
    gateways = set()
    for row in data.get('networks', {}).values():
        value = row.get('Gateway')
        if value:
            address = ipaddress.ip_address(value)
            require(address.version == 4 and address.is_private and not address.is_unspecified
                    and not address.is_loopback and not address.is_multicast, 'unsupported_gateway')
            gateways.add(str(address))
    require(0 < len(gateways) <= 8, 'gateway_unavailable')
    listeners = runner(['ss','-H','-ltn','sport = :18443'], timeout=10)
    require(not listeners.strip(), 'bridge_port_in_use')
    return {'status':'measured','backend':'127.0.0.1:8082','proposed_bridge':'127.0.0.1:18443',
            'port_observed_free':True,'port_reserved':False,'gateway_candidates':sorted(gateways),
            'effective_peer_proven':False,'public_bridge_denial_still_required':True}


def services_contract(runner):
    wanted = {'cron.service':'active','ustar-academy-cert-renew.timer':'active',
        'ustar-connect-receiver.service':'active','ustar-connect-notify.service':'inactive'}
    actual = {unit:runner(['systemctl','show','--value','-p','ActiveState',unit],timeout=10).strip()
              for unit in wanted}
    require(actual == wanted, 'managed_unit_state_changed')
    return {'status':'measured','units':actual,'credentials_read':False,'Telegram_API_called':False}


def contracts(*, root=Path('/'), runner=command, now=None, uid=0):
    root = Path(root); now = now or datetime.now(timezone.utc)
    result = {}
    for name, operation in [('https',lambda:https_contract(root,uid)),
                            ('cron',lambda:cron_contract(root,uid,now)),
                            ('transport',lambda:transport_contract(runner)),
                            ('services',lambda:services_contract(runner))]:
        try: result[name] = operation()
        except Exception as error:
            reason = str(error) if isinstance(error, Refusal) and re.fullmatch('[a-z_]{1,80}', str(error)) else 'contract_unavailable'
            result[name] = {'status':'review_required','reason':reason}
    result['status'] = 'measured' if all(v['status']=='measured' for v in result.values()) else 'review_required'
    return result


def validate_release(reference):
    require(reference['baseline_commit'] == BASELINE and reference['baseline']['versions'] == CURRENT
            and reference['candidate']['versions'] == TARGET, 'release_reference_mismatch')


def cron_check(reference):
    validate_release(reference)
    try: observed = cron_contract(Path('/'), 0, datetime.now(timezone.utc))
    except Exception as error:
        reason = str(error) if isinstance(error, Refusal) and re.fullmatch('[a-z_]{1,80}', str(error)) else 'contract_unavailable'
        observed = {'status':'review_required','reason':reason}
    return {'kind':'connect_cron_contract_readonly_preflight','source_commit':reference['source_commit'],
        'status':observed['status'],'cron':observed,'academy_source_rechecked':False,
        'other_contracts_rechecked':False,'deployment_approved':False,'host_commands':False,
        'php_or_moodle_bootstrap':False,'code_config_db_or_service_changes':False}


def check(reference):
    validate_release(reference)
    baseline = readiness.check(reference)
    observed = contracts()
    good = baseline['status'] == 'baseline_measured' and observed['status'] == 'measured'
    return {**baseline,'kind':'connect_chat_link_delivery_readonly_preflight',
        'status':'delivery_baseline_measured' if good else 'review_required','runtime_contracts':observed,
        'deployment_approved':False,'php_or_moodle_bootstrap':False,
        'remaining_gates':['fresh_paired_backup_and_guarded_1905_to_2000_update',
            'coordinated_cron_target_update','HTTPS_ownership_handoff_preserving_renewal_and_recovery',
            'private_TLS_bridge_actual_peer_and_public_denial_acceptance',
            'link_only_key_and_single_consumer_receiver_update','native_same_session_link_confirm_and_revoke',
            'separate_opt_in_notification_delivery_acceptance','external_HTTPS_before_MiniApp']}


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    modes=parser.add_mutually_exclusive_group()
    modes.add_argument('--check',action='store_true')
    modes.add_argument('--cron-only',action='store_true')
    args=parser.parse_args(argv)
    try:
        reference=readiness.load_reference()
        if not (args.check or args.cron_only):
            print(json.dumps({'status':'plan_only','source_commit':reference['source_commit'],
                'profile':'chat-link','host_commands':False,'next':'--check reads baseline/contracts; --cron-only reads only fixed cron files'}));return 0
        require(os.geteuid()==0 and sys.version_info>=(3,12),'root_python312_required')
        def expired(*_):raise Deadline()
        previous=signal.signal(signal.SIGALRM,expired);signal.alarm(90)
        try: result=cron_check(reference) if args.cron_only else check(reference)
        finally:signal.alarm(0);signal.signal(signal.SIGALRM,previous)
        print(json.dumps(result,ensure_ascii=False,indent=2));return 0 if result['status'] in ('delivery_baseline_measured','measured') else 2
    except (Exception, Deadline) as error:
        reason='probe_deadline' if isinstance(error,Deadline) else str(error) if isinstance(error,Refusal) and re.fullmatch('[a-z_]{1,80}',str(error)) else 'preflight_unavailable_no_changes'
        print(json.dumps({'status':'refused','reason':reason}));return 2


if __name__=='__main__':raise SystemExit(main())
