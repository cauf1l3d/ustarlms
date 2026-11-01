"""Explicit opt-in runtime. Default CLI validates local configuration without network."""
import argparse
import hmac
from dataclasses import dataclass
import json
import re
import signal
import threading

from .bridge import BridgeClient
from .private_files import private_directory, read_private, trusted_certificate
from .receiver import TelegramReceiver, ReceiverStopped, verify_bot
from .receiver_journal import Journal, JournalError
from .telegram_api import TelegramClient, TelegramError, _unique


@dataclass(repr=False)
class Configuration:
    bot_id: str
    bot_username: str
    directory: object
    onboarding_enabled: bool
    api: object
    bridge: object
    linking_enabled: bool = False
    notification_subjects: tuple | None = None
    acknowledgements_enabled: bool = False
    ack_bridge: object = None
    notification_contract: int = 1
    role_bot_enabled: bool = False
    bot_bridge: object = None
    bot_academy_origin: str | None = None


def load_config(path,*,notification=False):
    try:
        value = json.loads(read_private(path,16384),object_pairs_hook=_unique)
        fields = {'bot_token_file','bot_username','state_directory','telegram_proxy','onboarding_enabled',
                  'bridge_endpoint','bridge_key_id','bridge_key_file','bridge_ca_file'}
        if notification:fields=fields|{'notification_subjects'}
        optional = {'linking_enabled'} | ({'notification_contract','role_bot_delivery'} if notification else
            {'acknowledgements_enabled', 'ack_bridge_key_id', 'ack_bridge_key_file',
             'role_bot_enabled','bot_bridge_key_id','bot_bridge_key_file','bot_academy_origin'})
        if not isinstance(value,dict) or not fields <= set(value) or not set(value) <= fields | optional:
            raise ValueError('invalid_configuration')
        ack_enabled = value.get('acknowledgements_enabled', False)
        role_bot=value.get('role_bot_enabled',False)
        role_delivery=value.get('role_bot_delivery',False)
        if type(role_delivery) is not bool:raise ValueError('invalid_bot_configuration')
        bot_fields={'bot_bridge_key_id','bot_bridge_key_file','bot_academy_origin'}
        if (type(role_bot) is not bool or (role_bot and not bot_fields<=set(value))
                or (not role_bot and bot_fields.intersection(value)) or (role_bot and value['onboarding_enabled'])):
            raise ValueError('invalid_bot_configuration')
        contract = value.get('notification_contract', 1)
        if (type(ack_enabled) is not bool or type(contract) is not int or contract not in (1, 2, 3)
                or (ack_enabled and not {'ack_bridge_key_id', 'ack_bridge_key_file'} <= set(value))
                or (not ack_enabled and {'ack_bridge_key_id', 'ack_bridge_key_file'}.intersection(value))):
            raise ValueError('invalid_configuration')
        subjects=None
        if notification:
            subjects=value['notification_subjects']
            if role_delivery:
                if (subjects!='academy_linked_opt_in' or value['onboarding_enabled'] is not False
                        or value.get('linking_enabled',False) is not False or contract!=3):
                    raise ValueError('invalid_notification_configuration')
                subjects=None
            elif (not isinstance(subjects,list) or len(subjects)!=1
                    or not isinstance(subjects[0],str) or not re.fullmatch('[1-9][0-9]{0,15}',subjects[0])
                    or int(subjects[0])>=2**52 or value['onboarding_enabled'] is not False
                    or value.get('linking_enabled',False) is not False):
                raise ValueError('invalid_notification_configuration')
            subjects=tuple(subjects) if subjects is not None else None
        linking = value.get('linking_enabled', False)
        if type(linking) is not bool:
            raise ValueError('invalid_configuration')
        username = value['bot_username']
        if not isinstance(username,str) or not re.fullmatch(r'[A-Za-z0-9_]{5,32}',username) or type(value['onboarding_enabled']) is not bool:
            raise ValueError('invalid_configuration')
        directory = private_directory(value['state_directory'])
        token = read_private(value['bot_token_file'],512).strip()
        api = TelegramClient(token,proxy=value['telegram_proxy'],**({'role_navigation':True} if role_bot or role_delivery else {}))
        bridge, key, ack_bridge = None, None, None
        ca = value['bridge_ca_file']
        if (value['onboarding_enabled'] or linking or notification or ack_enabled or role_bot) and ca is not None:
            ca = trusted_certificate(ca)
        if value['onboarding_enabled'] or linking or notification:
            key = read_private(value['bridge_key_file'],128).strip()
            bridge = BridgeClient(value['bridge_endpoint'],value['bridge_key_id'],key,ca_file=ca)
        if ack_enabled:
            ack_key = read_private(value['ack_bridge_key_file'],128).strip()
            if (value['ack_bridge_key_id'] == value['bridge_key_id']
                    or (key is not None and hmac.compare_digest(key, ack_key))):
                raise ValueError('invalid_ack_configuration')
            ack_bridge = BridgeClient(value['bridge_endpoint'],value['ack_bridge_key_id'],ack_key,ca_file=ca)
        bot_bridge=None
        if role_bot:
            from urllib.parse import urlsplit
            origin=urlsplit(value['bot_academy_origin'])
            if origin.scheme!='https' or not origin.hostname or origin.username or origin.password or origin.path not in ('','/') or origin.query or origin.fragment:
                raise ValueError('invalid_bot_origin')
            bot_key=read_private(value['bot_bridge_key_file'],128).strip()
            if value['bot_bridge_key_id'] in (value['bridge_key_id'],value.get('ack_bridge_key_id')) or any(
                    other is not None and hmac.compare_digest(other,bot_key) for other in [key,locals().get('ack_key')]):
                raise ValueError('invalid_bot_configuration')
            bot_bridge=BridgeClient(value['bridge_endpoint'],value['bot_bridge_key_id'],bot_key,ca_file=ca)
        return Configuration(token.split(':',1)[0],username,directory,value['onboarding_enabled'],api,bridge,
                             linking,subjects,ack_enabled,ack_bridge,contract,role_bot,bot_bridge,value.get('bot_academy_origin'))
    except Exception:
        raise ValueError('configuration_invalid') from None


def main(argv=None):
    parser = argparse.ArgumentParser(description='USTAR Academy Telegram receiver; local validation unless --run.')
    parser.add_argument('--config',required=True,help='Absolute owner-private JSON configuration path')
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--run',action='store_true',help='Explicitly allow Telegram API reads and private replies')
    mode.add_argument('--probe',action='store_true',help='Read-only getMe/webhook verification; no updates or sends')
    args = parser.parse_args(argv)
    journal = None
    try:
        config = load_config(args.config)
        if args.probe:
            verify_bot(config.api, config.bot_id, config.bot_username, require_main_app=config.onboarding_enabled)
            if config.linking_enabled:
                config.bridge.probe_link_scope()
            if config.acknowledgements_enabled:
                config.ack_bridge.probe_ack_scope()
            if config.role_bot_enabled:
                config.bot_bridge.probe_bot_scope()
            print(json.dumps({'status':'telegram_verified','bot_username':config.bot_username,
                              'updates_consumed':False,'onboarding_enabled':config.onboarding_enabled,
                              'linking_enabled':config.linking_enabled,
                              'link_scope':'verified' if config.linking_enabled else 'disabled'}))
            return 0
        if not args.run:
            print(json.dumps({'status':'configuration_valid','network_used':False,
                              'onboarding_enabled':config.onboarding_enabled,'linking_enabled':config.linking_enabled}))
            return 0
        journal = Journal(config.directory,config.bot_id)
        receiver = TelegramReceiver(config.api,config.bridge,journal,bot_id=config.bot_id,
            bot_username=config.bot_username,onboarding_enabled=config.onboarding_enabled,
            linking_enabled=config.linking_enabled,acknowledgements_enabled=config.acknowledgements_enabled,
            ack_bridge=config.ack_bridge,role_bot_enabled=config.role_bot_enabled,
            bot_bridge=config.bot_bridge,bot_academy_origin=config.bot_academy_origin)
        stop = threading.Event()
        def requested_stop(_signal,_frame):
            stop.set()
        signal.signal(signal.SIGINT,requested_stop)
        signal.signal(signal.SIGTERM,requested_stop)
        while not stop.is_set():
            result = receiver.step(stop=stop)
            print(json.dumps({**result,'health':journal.health()},sort_keys=True),flush=True)
            if result['status'] in ('waiting','backoff'):
                stop.wait(min(60,max(1,result.get('retry_after',1))))
        print('{"status":"stopped","reason":"shutdown_requested"}',flush=True)
        return 0
    except ValueError:
        print('{"status":"stopped","reason":"configuration_invalid"}',flush=True)
        return 2
    except ReceiverStopped as error:
        print(json.dumps({'status':'stopped','reason':str(error)}),flush=True)
        return 3
    except TelegramError as error:
        print(json.dumps({'status':'stopped','reason':error.reason}),flush=True)
        return 6
    except JournalError as error:
        print(json.dumps({'status':'stopped','reason':str(error)}),flush=True)
        return 4
    except Exception:
        print('{"status":"stopped","reason":"runtime_unavailable"}',flush=True)
        return 5
    finally:
        if journal is not None:
            journal.close()


if __name__=='__main__':
    raise SystemExit(main())
