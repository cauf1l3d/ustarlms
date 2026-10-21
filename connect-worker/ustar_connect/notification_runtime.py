"""Explicit sending runtime; default validates private local files without network."""
import argparse
import json
import signal
import threading
import time

from .receiver_runtime import load_config
from .notification_worker import NotificationWorker
from .receiver import verify_bot


def main(argv=None):
    parser=argparse.ArgumentParser(description='Canonical Academy notifications; local validation unless --run.')
    parser.add_argument('--config',required=True)
    modes=parser.add_mutually_exclusive_group()
    modes.add_argument('--run',action='store_true')
    modes.add_argument('--probe',action='store_true')
    args=parser.parse_args(argv)
    try:
        config=load_config(args.config,notification=True)
        if config.bridge is None:raise ValueError()
        if args.probe:
            verify_bot(config.api,config.bot_id,config.bot_username)
            # A hot PHP include handoff may need a short opcode-cache recheck.
            # Retry only this no-nonce/no-delivery denial proof, never lease/send.
            deadline=time.monotonic()+8
            while True:
                try:config.bridge.probe_notification_scope();break
                except ValueError:
                    if time.monotonic()>=deadline:raise
                    time.sleep(.25)
            print('{"status":"notification_probe_verified","updates_consumed":false,"notifications_sent":false}')
            return 0
        if not args.run:
            print('{"status":"configuration_valid","network_used":false}')
            return 0
        worker=NotificationWorker(config.api,config.bridge,bot_id=config.bot_id,bot_username=config.bot_username,
            allowed_subjects=config.notification_subjects)
        stop=threading.Event()
        def shutdown(_signal,_frame):stop.set()
        signal.signal(signal.SIGINT,shutdown);signal.signal(signal.SIGTERM,shutdown)
        while not stop.is_set():
            result=worker.step()
            print(json.dumps(result,sort_keys=True),flush=True)
            if result['status']=='stopped':return 3
            stop.wait(min(60,max(1,result.get('retry_after',1))))
        return 0
    except Exception:
        print('{"status":"stopped","reason":"runtime_unavailable"}',flush=True)
        return 2


if __name__=='__main__':raise SystemExit(main())
