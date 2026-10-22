#!/usr/bin/env python3
"""Pure profiles: exact accepted fence, separate v2 sender and ACK-only credentials."""
import json
import re
from connect_con02_contract import require, read_fence, frozen_legacy
from connect_con02_state import unique

NOTIFY_ID = 'tg_notify_v2'
ACK_ID = 'tg_ack_v1'
ENDPOINT = 'https://127.0.0.1:18443/local/ustar/connect_bridge.php'


def config(raw, unit):
    value = json.loads(raw, object_pairs_hook=unique)
    service = 'notify' if unit == 'notifications' else unit
    cred = '/run/credentials/ustar-connect-' + service + '.service/'
    base = {'bot_token_file': cred + 'telegram.token', 'bot_username': 'ustarcorp_bot',
            'state_directory': '/var/lib/ustar-connect-' + ('notify' if service == 'notify' else 'receiver'),
            'telegram_proxy': 'http://127.0.0.1:18081', 'onboarding_enabled': False,
            'bridge_endpoint': ENDPOINT, 'bridge_key_id': 'tg_notify_pilot_v1' if service == 'notify' else 'tg_link_v1',
            'bridge_key_file': cred + 'bridge.key', 'bridge_ca_file': cred + ('bridge-ca.crt' if service == 'notify' else 'bridge.ca')}
    optional = {'linking_enabled'}
    if service == 'notify': base['notification_subjects'] = value.get('notification_subjects')
    require(isinstance(value, dict) and set(base) <= set(value) <= set(base) | optional
            and all(value.get(k) == v and type(value.get(k)) is type(v) for k, v in base.items())
            and value.get('linking_enabled', False) is (service != 'notify'), 'unknown_runtime_configuration')
    return value


def profiles(ctx, before, *, generation, cutoff, notify_secret, ack_secret):
    require(isinstance(generation, str) and re.fullmatch('[a-f0-9]{32}', generation)
            and type(cutoff) is int and cutoff > 0, 'runtime_generation')
    raw = {k: v['raw'] for k, v in before['files'].items()}
    configs = {n: config(raw[n], n) for n in ('receiver', 'probe', 'notifications')}
    fence = read_fence(raw['include'], configs['notifications'], configs['receiver'])
    require(all(before['fence'].get(k) == v for k, v in fence.items()), 'notification_fence_changed')
    link = raw['link_key'].strip(); old_notify = raw['notify_key'].strip()
    keys = (link, old_notify, notify_secret, ack_secret)
    require(all(isinstance(k, bytes) and re.fullmatch(b'[a-f0-9]{64}', k) for k in keys)
            and len(set(keys)) == 4, 'runtime_keys_not_distinct')
    # Reconstruct the accepted include, rather than accepting arbitrary PHP with
    # recognizable lines. No current-link lookup (a revoke is a valid state).
    tenants = re.findall(rb"'tenant'=>'([1-9][0-9]{0,19})'", raw['include'])
    require(tenants and len(set(tenants)) == 1, 'include_tenant_changed')
    tenant = tenants[0].decode()
    legacy = frozen_legacy(ctx.bundle)
    expected = legacy['connect_private_bridge'].php_settings(link.decode(), tenant)
    expected += ("\n// USTAR one-employee notification pilot; separate key and new events only.\n"
        "$CFG->ustar_connect_bridge_keys['tg_notify_pilot_v1']=['secret'=>'" + old_notify.decode() +
        "','provider'=>'telegram','tenant'=>'" + tenant + "','operations'=>['notification.lease','notification.begin','notification.finish']];\n"
        "$CFG->ustar_connect_notify_userids=[" + str(fence['notification_pilot_userids'][0]) + "];\n"
        "$CFG->ustar_connect_notify_since=" + str(fence['notify_since']) + ";\n"
        "$CFG->ustar_connect_notifications_enabled=" + ('true' if fence['notifications_enabled'] else 'false') + ";\n").encode()
    require(expected == raw['include'] and raw['notify_dropin'] == legacy['connect_notification_pilot'].DROPIN,
            'unknown_private_include_or_sender_override')
    include = expected + ("\n// USTAR CON02 runtime generation " + generation + "; separate callback capability.\n"
        "$CFG->ustar_connect_bridge_keys['" + NOTIFY_ID + "']=['secret'=>'" + notify_secret.decode() +
        "','provider'=>'telegram','tenant'=>'" + tenant + "','operations'=>['notification.lease','notification.begin_v2','notification.finish']];\n"
        "$CFG->ustar_connect_bridge_keys['" + ACK_ID + "']=['secret'=>'" + ack_secret.decode() +
        "','provider'=>'telegram','tenant'=>'" + tenant + "','operations'=>['notification.ack']];\n"
        "$CFG->ustar_connect_ack_since=" + str(cutoff) + ";\n$CFG->ustar_connect_ack_enabled=true;\n").encode()
    result = {'include': include, 'notify_key': notify_secret + b'\n', 'ack_key': ack_secret + b'\n',
              'notify_dropin': raw['notify_dropin']}
    for n, cfg in configs.items():
        cfg = dict(cfg)
        if n == 'notifications': cfg.update(notification_contract=2, bridge_key_id=NOTIFY_ID)
        else:
            cfg.update(acknowledgements_enabled=True, ack_bridge_key_id=ACK_ID,
                       ack_bridge_key_file='/run/credentials/ustar-connect-' + n + '.service/ack-bridge.key')
            result[n + '_ack_dropin'] = (b'# USTAR managed CON02 separate ACK capability\n[Service]\n'
                b'LoadCredential=ack-bridge.key:/etc/ustar-connect/ack-bridge.key\n')
        result[n] = (json.dumps(cfg, sort_keys=True) + '\n').encode()
    return result
