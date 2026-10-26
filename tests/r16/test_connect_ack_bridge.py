"""Narrow callback transport signatures/timeouts/probes; no remote effects."""
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock
from urllib.error import HTTPError
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'connect-worker'))
from ustar_connect.bridge import BridgeClient, BridgeRejected, sign_request


class Response(io.BytesIO):
    def __enter__(self): return self
    def __exit__(self,*a): self.close()


class AckBridge(unittest.TestCase):
    def setUp(self):
        self.client=BridgeClient('https://127.0.0.1:18443/local/ustar/connect_bridge.php','tg_ack','a'*64)
        self.client._opener=Mock()

    def test_versioned_and_ack_operations_are_signed_without_changing_envelope(self):
        for op,payload in [('notification.begin_v2',{'delivery':'7','lease':'ud1_'+'A'*43}),
                ('notification.ack',{'action':'ua1_'+'A'*43,'message_id':'991',
                                      'provider':'telegram','subject':'77777','tenant':'123456'})]:
            try: value=sign_request(op,payload,key_id='tg_ack',secret_hex='a'*64,now=1800000000)
            except ValueError: self.fail(op+' not supported')
            self.assertEqual(op,json.loads(value.body)['operation'])
            self.assertEqual(payload,json.loads(value.body)['payload'])
            self.assertEqual(64,len(value.headers['X-Ustar-Signature']))

    def test_callback_request_is_bounded_five_seconds_without_retry(self):
        import inspect
        self.assertIn('timeout',inspect.signature(self.client.request).parameters)
        self.client._opener.open.return_value=Response(b'{"ok":true,"result":{}}')
        self.assertEqual({'ok':True,'result':{}},self.client.request('notification.ack',{'action':'unused'},timeout=5))
        self.assertEqual(5,self.client._opener.open.call_args.kwargs['timeout'])
        self.assertEqual(1,self.client._opener.open.call_count)
        for bad in (True,1,100,None,'5'):
            with self.assertRaises(ValueError):self.client.request('notification.ack',{},timeout=bad)

    def test_fixed_ack_rejections_do_not_accept_payload_text(self):
        for reason in ('ack_unavailable','ack_disabled'):
            try: error=BridgeRejected(reason)
            except ValueError:self.fail('missing fixed rejection '+reason)
            self.assertEqual(reason,error.reason)
        with self.assertRaises(ValueError):BridgeRejected('secret user details')

    def test_ack_scope_proves_denial_without_a_poll_or_business_payload(self):
        self.assertTrue(hasattr(self.client,'probe_ack_scope'))
        calls=[]
        def opening(request,timeout):
            calls.append(json.loads(request.data))
            raise HTTPError(request.full_url,403,'fixed',{},io.BytesIO(b'{"ok":false,"reason":"forbidden_operation"}'))
        self.client._opener.open.side_effect=opening
        self.client.probe_ack_scope()
        self.assertEqual(['link.claim','notification.lease'],[x['operation'] for x in calls])
        self.assertTrue(all(set(x['payload'])=={'probe'} for x in calls))


if __name__=='__main__':unittest.main()
