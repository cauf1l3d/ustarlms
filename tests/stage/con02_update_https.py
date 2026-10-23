#!/usr/bin/env python3
"""New controller with actual HTTPS owner/Apache renewal; SQL/systemd are modeled here."""
import json
import os
from pathlib import Path
import sys
from types import SimpleNamespace

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'tests/stage'),str(ROOT/'scripts/infra'),str(ROOT/'scripts')]
import connect_con02_contract as c
from connect_con02_runtime import Runtime
from con02_update_systemd import artifact_bundle,FileHost,populate,fixture_context


def main():
    if (os.geteuid()!=0 or os.environ.get('GITHUB_ACTIONS')!='true'
            or os.environ.get('USTAR_CONNECT_CON02_FIXTURE')!='1' or Path('/opt/ustar').exists()):
        raise RuntimeError('disposable_ci_only')
    if len(sys.argv) not in (3,4):raise RuntimeError('plain_artifact_and_frozen_worker_required')
    bundle=artifact_bundle(sys.argv[1]);archive=Path(sys.argv[2])
    legacy=c.frozen_legacy(bundle)
    commit,values=legacy['connect_install'].validate_archive(archive,c.sha(archive.read_bytes()));assert commit==c.LEGACY_COMMIT
    import academy_https_native as native
    def verification(manager,owner,root):
        class Host(FileHost):
            def __init__(self):
                super().__init__(bundle,root)
                self.states={n:{'active':n==c.SERVICES[1],'enabled':n==c.SERVICES[1]} for n in c.SERVICES}
                self.try_concurrent=False;self.serialized=False
            def legacy(self):return SimpleNamespace(manager=manager)
            def https_snapshot(self):return c.Host.https_snapshot(self)
            def service_active(self,n):return self.states[n]['active']
            def service_enabled(self,n):return self.states[n]['enabled']
            def service(self,action,n):
                if action in ('start','stop'):self.states[n]['active']=action=='start'
                elif action in ('enable','disable'):self.states[n]['enabled']=action=='enable'
            def probe(self,profile):
                if self.try_concurrent:
                    self.try_concurrent=False
                    try:manager.renew()
                    except BlockingIOError:self.serialized=True
                    else:raise AssertionError('concurrent_HTTPS_owner_not_serialized')
            def validate_profiles(self,profiles):
                owner.lint(owner.runtime(manager.p.old.config),profiles['include'])
        host=Host();populate(bundle,root,host,values,manager.p.old.ca.read_bytes())
        ctx,journal,locks=fixture_context(bundle,root,host)
        first=c.https_snapshot(ctx)
        owner.force_due=True;assert manager.renew()['phase']=='renewed'
        second=c.https_snapshot(ctx);assert first!=second
        op=Runtime(ctx,journal,locks)
        assert op.enable()['phase']=='runtime_active'
        assert c.https_snapshot(ctx)==second
        assert op.disable()['phase']=='runtime_disabled' and c.https_snapshot(ctx)==second
        owner.lose_ack=True
        try:manager.renew()
        except native.x.Refusal:pass
        else:raise AssertionError('lost_owner_reload_ack_accepted')
        assert c.https_snapshot(ctx)==second
        host.try_concurrent=True
        assert op.enable()['phase']=='runtime_active' and host.serialized
        assert c.https_snapshot(ctx)==second
        assert op.disable()['phase']=='runtime_disabled'
        assert manager.renew()['phase']=='renewed' and c.https_snapshot(ctx)!=second
        report={'result':'CON02_UPDATE_HTTPS_OWNER=PASS','actual_Apache_OpenSSL_PHP':True,
            'new_controller_enable_disable_generations':True,'all_HTTPS_owned_bytes_unchanged_within_operations':True,
            'authorized_leaf_renewal_between_operations_accepted':True,'concurrent_owner_serialized':True,
            'failed_owner_reload_restores_previous_leaf':True,'systemd_and_application_SQL':'modeled here; separate actual runtime/native DB gates',
            'provider_requests':False,'production_access':False}
        (ROOT/'artifacts/con02-update-https-owner.json').write_text(json.dumps(report,indent=2)+'\n')
        print('CON02_UPDATE_HTTPS_OWNER=PASS')
        if len(sys.argv)==4:
            # An owner-registered independent virtual host, as with AutoReport,
            # remains part of the exact topology during the successor handoff.
            from telegram_update_runtime import artifact_bundle as successor_bundle, verify as verify_successor
            site=manager.p.old.enabled.parent/'ustar-autoreport.conf'
            site.write_text('Listen 127.0.0.1:19443\n<VirtualHost 127.0.0.1:19443>\nServerName autoreport.invalid\nDocumentRoot /var/www/html\n</VirtualHost>\n')
            site.chmod(0o644)
            owner.test_config();owner.reload()
            saved=manager.load();observed=owner.vhosts()
            binding='127.0.0.1:19443'
            assert observed[binding][0]['name']=='autoreport.invalid'
            saved['topology'][binding]=observed[binding]
            manager.topology(saved,observed);manager.save(saved)
            before_site=site.read_bytes()
            verify_successor(successor_bundle(sys.argv[3]),ctx,journal,locks,host)
            assert site.read_bytes()==before_site and owner.vhosts()[binding]==observed[binding]
    os.environ['USTAR_ACADEMY_TLS_FIXTURE']='1'
    native.main(verification=verification)


if __name__=='__main__':main()
