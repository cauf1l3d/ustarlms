"""Explicit sending runtime; default validates private local files without network."""
import argparse
import json
import signal
import threading

from .receiver_runtime import load_config
from .notification_worker import NotificationWorker


def main(argv=None):
    parser=argparse.ArgumentParser(description='Canonical Academy notifications; local validation unless --run.')
    parser.add_argument('--config',required=True)
    parser.add_argument('--run',action='store_true')
    args=parser.parse_args(argv)
    try:
        config=load_config(args.config)
        if config.bridge is None:raise ValueError()
        if not args.run:
            print('{"status":"configuration_valid","network_used":false}')
            return 0
        worker=NotificationWorker(config.api,config.bridge,bot_id=config.bot_id,bot_username=config.bot_username)
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
