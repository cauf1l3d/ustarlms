"""Real WSGI/verifier/session guards, synthetic private Moodle boundary only."""
import io
import json
import unittest
import test_connect_web as web_fixture
from test_connect_identity import NOW
from ustar_connect.bridge import BridgeRejected

ACTOR='ua1_'+'B'*42+'A'


class ActorRemote(web_fixture.RemoteBoundary):
    def __init__(self,clock):
        super().__init__();self.state='linked_active';self.clock=clock;self.actor_failure=None
        self.opened=0;self.extra={};self.open_override=None
    def request(self,op,payload):
        if not op.startswith('actor.'):
            return super().request(op,payload)
        self.calls.append((op,dict(payload)))
        if self.actor_failure:raise self.actor_failure
        now=self.clock()
        if op=='actor.open':
            if self.state!='linked_active':raise BridgeRejected('native_auth_required')
            self.opened+=1
            result=self.open_override or {'session':ACTOR,'scope':'actor_read','expires_at':now+28800,'idle_expires_at':now+900}
        elif op=='actor.revoke':result={'revoked':True}
        else:
            result={'scope':'actor_read','expires_at':NOW+28800,'idle_expires_at':min(NOW+28800,now+900),
                'features':['profile'],'profile':{'username':'synthetic','fullname':'<img src=x onerror=alert(1)>',
                'position':'Сотрудник','department':'Розница','account_state':'active'},**self.extra}
        return {'ok':True,'result':result}


class ActorWebTests(unittest.TestCase):
    def call(self,*args,**kwargs):
        result=web_fixture.BrowserBoundary.call(self,*args,**kwargs)
        if 'Max-Age=0' in result['headers'].get('Set-Cookie',''):self.cookie=''
        return result
    bootstrap=web_fixture.BrowserBoundary.bootstrap
    login=web_fixture.BrowserBoundary.login
    def setUp(self):
        web_fixture.BrowserBoundary.setUp(self)
        self.remote=ActorRemote(lambda:self.now);self.app._bridge=self.remote
    def activate(self):
        self.login();r=self.call('/api/activate',{})
        self.assertEqual(200,r['status'],r['body']);self.csrf=r['body']['csrf'];return r
    def test_explicit_activation_rotates_cookie_and_never_exports_actor_token(self):
        self.login();oldcookie,oldcsrf=self.cookie,self.csrf
        r=self.call('/api/activate',{});self.assertEqual(200,r['status'],r['body'])
        self.csrf=r['body']['csrf'];self.assertNotEqual(oldcookie,self.cookie);self.assertNotEqual(oldcsrf,self.csrf)
        self.assertEqual('actor_read',r['body']['scope']);self.assertNotIn(ACTOR,r['raw'].decode())
        self.assertEqual(1,self.remote.opened)
        self.assertEqual({'session':'ue1_'+'A'*43,'browser':self.remote.calls[-1][1]['browser']},self.remote.calls[-1][1])
        self.assertEqual(403,self.call('/api/status',{},headers={'HTTP_COOKIE':oldcookie})['status'])
    def test_status_is_current_self_profile_no_client_actor_or_secret(self):
        self.activate();r=self.call('/api/status',{});self.assertEqual(200,r['status'])
        self.assertEqual('synthetic',r['body']['profile']['username']);self.assertEqual(['profile'],r['body']['features'])
        self.assertNotIn(ACTOR,r['raw'].decode());self.assertNotIn('ue1_',r['raw'].decode())
        self.assertEqual('actor.status',self.remote.calls[-1][0])
        self.assertEqual(400,self.call('/api/status',{'userid':'2'})['status'])
    def test_pending_entry_does_not_open_an_actor(self):
        self.remote.state='pending_approval';self.login()
        r=self.call('/api/activate',{});self.assertEqual(403,r['status'])
        self.assertEqual('native_auth_required',r['body']['reason']);self.assertEqual(0,self.remote.opened)
    def test_csrf_origin_and_arbitrary_fields_fail_before_activation(self):
        self.login();before=len(self.remote.calls)
        for data,headers in [({}, {'HTTP_X_CSRF_TOKEN':'wrong'}),({}, {'HTTP_ORIGIN':'https://evil.invalid'}),
                             ({'session':ACTOR},{}),({'userid':'2'}, {})]:
            self.assertIn(self.call('/api/activate',data,headers=headers)['status'],(400,403))
        self.assertEqual(before,len(self.remote.calls))
    def test_unknown_activation_outcome_is_not_retried_even_on_reload(self):
        self.login();self.remote.actor_failure=ValueError('PRIVATE upstream')
        r=self.call('/api/activate',{});self.assertEqual(503,r['status'])
        self.assertEqual('activation_outcome_unknown',r['body']['reason'])
        self.remote.actor_failure=None
        self.assertEqual(503,self.call('/api/activate',{})['status'])
        self.bootstrap();self.assertEqual(503,self.call('/api/status',{})['status'])
        self.assertEqual(1,len([op for op,_ in self.remote.calls if op=='actor.open']))
    def test_malformed_activation_is_unknown_not_a_browser_grant(self):
        self.login();self.remote.open_override={'session':ACTOR,'scope':'actor_read','expires_at':NOW+28801,'idle_expires_at':NOW+900}
        r=self.call('/api/activate',{});self.assertEqual('activation_outcome_unknown',r['body']['reason'])
        self.assertNotIn(ACTOR,r['raw'].decode());self.assertEqual(503,self.call('/api/activate',{})['status'])
    def test_bootstrap_does_not_renew_but_current_status_renews_idle(self):
        self.activate();self.now+=800;self.bootstrap()
        r=self.call('/api/status',{});self.assertEqual(200,r['status']);self.assertIn('Max-Age=900',r['headers']['Set-Cookie'])
        self.now+=800;self.bootstrap();self.assertTrue(self.call('/api/bootstrap')['body']['authenticated'])
        self.now+=101;r=self.call('/api/bootstrap');self.assertFalse(r['body']['authenticated'])
    def test_actor_rejections_clear_browser_authority_and_do_not_fallback_to_entry(self):
        self.activate();self.remote.actor_failure=BridgeRejected('invalid_actor')
        self.assertEqual(403,self.call('/api/status',{})['status']);self.assertFalse(self.call('/api/bootstrap')['body']['authenticated'])
        self.assertFalse(any(op=='registration.status' for op,_ in self.remote.calls))
    def test_upstream_extra_sensitive_fields_are_rejected_not_reflected(self):
        self.activate();self.remote.extra={'password':'PRIVATE-PASSWORD'}
        r=self.call('/api/status',{});self.assertEqual(503,r['status']);self.assertNotIn('PRIVATE',r['raw'].decode())
    def test_native_mfa_error_is_typed_and_does_not_become_generic_failure(self):
        self.login();self.remote.actor_failure=BridgeRejected('native_auth_required')
        r=self.call('/api/activate',{});self.assertEqual(403,r['status']);self.assertEqual('native_auth_required',r['body']['reason'])
    def test_logout_closes_local_actor_when_upstream_unavailable(self):
        self.activate();self.remote.actor_failure=ValueError('PRIVATE')
        r=self.call('/api/logout',{});self.assertEqual(200,r['status']);self.assertEqual('actor.revoke',self.remote.calls[-1][0])
        self.assertFalse(self.call('/api/bootstrap')['body']['authenticated'])
    def test_actor_session_cannot_use_registration_or_reactivate(self):
        self.activate();before=len(self.remote.calls)
        self.assertEqual(403,self.call('/api/options',{})['status'])
        self.assertEqual(403,self.call('/api/activate',{})['status'])
        self.assertEqual(before,len(self.remote.calls))
    def test_restart_discards_ram_authority_and_bridge_signer_accepts_only_named_operations(self):
        self.activate();self.app=self.module.OnboardingApp(self.app.origin,self.app._bot_token,self.remote,clock=lambda:self.now)
        self.assertFalse(self.call('/api/bootstrap')['body']['authenticated'])
        from ustar_connect.bridge import sign_request
        for op in ('actor.open','actor.status','actor.revoke'):
            req=sign_request(op,{'session':ACTOR,'browser':'ub1_'+'A'*43},key_id='tg1',secret_hex='12'*32,now=NOW)
            self.assertEqual(op,json.loads(req.body)['operation'])


if __name__=='__main__':unittest.main()
