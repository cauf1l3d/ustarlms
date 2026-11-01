#!/usr/bin/env python3
"""Deterministic, exact-Git app+worker role bot package; never activates a host."""
import ast
import base64
import contextlib
import gzip
import importlib.util
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import xml.etree.ElementTree as ET
import connect_con02_contract as c
import connect_role_bot_update as m
from build_feed_media_update import reference_from_git as previous_reference
import feed_media_update as feed
import perf_query_update as perf
import studio_viewport_update as viewport

ROOT=Path(__file__).resolve().parents[2]


def git(repo,*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL,timeout=30)


def derive_observer(parent):
    c.require(parent.count(b"('2026102802','2026102803')")==1,'observer_fixed_version_profile')
    adapted=parent.replace(b"('2026102802','2026102803')",b"('2026102802','2026102803','2026102900')")
    compile(adapted,'<role-bot-passive-observer>','exec');return adapted


def reference_from_git(repo,tool_commit):
    repo=Path(repo)
    c.require(re.fullmatch('[a-f0-9]{40}',tool_commit) and git(repo,'rev-parse',tool_commit+'^{commit}').decode().strip()==tool_commit,
        'exact_tool_commit_required')
    previous=previous_reference(repo,m.PREDECESSOR_TOOL);payload={}
    for row in git(repo,'ls-tree','-r','-z',m.APP_COMMIT,'--','moodle/local/ustar','moodle/theme/ustar').split(b'\0'):
        if not row:continue
        meta,path=row.split(b'\t',1);mode,kind,oid=meta.decode().split()
        c.require(kind=='blob' and mode in ('100644','100755'),'regular_git_source_required')
        payload[path.decode().removeprefix('moodle/')]=git(repo,'cat-file','blob',oid)
    fields,indexes={},{}
    for table in ET.fromstring(payload['local/ustar/db/install.xml']).findall('.//TABLE'):
        name=table.attrib['NAME']
        fields[name]={f.attrib['NAME']:{'type':f.attrib['TYPE'],'length':f.attrib.get('LENGTH'),
            'notnull':f.attrib.get('NOTNULL')=='true','default':f.attrib.get('DEFAULT'),'sequence':f.attrib.get('SEQUENCE')=='true'}
            for f in table.findall('./FIELDS/FIELD')}
        indexes[name]=[{'fields':[f.strip() for f in k.attrib['FIELDS'].split(',')],
            'unique':k.attrib.get('TYPE') in ('primary','unique','foreign-unique') or k.attrib.get('UNIQUE')=='true'}
            for k in [*table.findall('./KEYS/KEY'),*table.findall('./INDEXES/INDEX')]]
    versions={key:int(re.search(rb'\$plugin->version\s*=\s*(\d+)',payload[scope+'/version.php'])[1])
        for key,scope in [('local_ustar','local/ustar'),('theme_ustar','theme/ustar')]}
    target={'files':{n:c.sha(raw) for n,raw in sorted(payload.items())},'tables':sorted(fields),'versions':versions,
        'schema_fields':fields,'schema_indexes':indexes}
    ref={'schema':1,'tool_commit':tool_commit,'app_commit':m.APP_COMMIT,'worker_commit':m.WORKER_COMMIT,
        'baseline_commit':m.BASELINE_COMMIT,'legacy_commit':c.LEGACY_COMMIT,'baseline':previous.reference['candidate'],'candidate':target}
    m.validate_reference(ref)
    spec=importlib.util.spec_from_file_location('_role_bot_worker_builder',repo/'connect-worker/deploy/build_release.py')
    builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
    with tempfile.TemporaryDirectory() as td,contextlib.redirect_stdout(io.StringIO()):
        worker=builder.build(m.WORKER_COMMIT,td).read_bytes()
    return m.Bundle(ref,{},payload,worker,previous.legacy,previous)


def assets_for(bundle):
    predecessor=bundle.predecessor;chain=[];item=predecessor.predecessor
    for name in ('perf.json','viewport.json','colors.json','materials.json','studio.json','telegram.json','con02.json'):
        chain.append((name,item));item=getattr(item,'predecessor',None)
    return {'reference.json':(json.dumps(bundle.reference,sort_keys=True)+'\n').encode(),
        'feed.json':(json.dumps(predecessor.reference,sort_keys=True)+'\n').encode(),
        **{name:(json.dumps(item.reference,sort_keys=True)+'\n').encode() for name,item in chain},
        'worker.tar.gz':bundle.worker_archive,'baseline-worker.tar.gz':predecessor.worker_archive,
        'predecessor-worker.tar.gz':chain[-1][1].worker_archive,
        **{'payload/'+n:raw for n,raw in bundle.payload.items()},
        **{'baseline2803/'+n:predecessor.payload[n] for n in m.CHANGED},
        **{'baseline2802/'+n:predecessor.predecessor.payload[n] for n in feed.CHANGED},
        **{'baseline2801/'+n:predecessor.predecessor.predecessor.payload[n] for n in perf.CHANGED},
        **{'baseline/'+n:predecessor.predecessor.predecessor.predecessor.payload[n] for n in viewport.CHANGED},
        **{'legacy/'+n:raw for n,raw in bundle.legacy.items()}}


def build(tool_commit,output,*,repo=ROOT):
    repo=Path(repo)
    for scope,qualified in [('moodle/local/ustar',m.APP_COMMIT),('moodle/theme/ustar',m.APP_COMMIT),('connect-worker',m.WORKER_COMMIT)]:
        c.require(git(repo,'rev-parse',tool_commit+':'+scope)==git(repo,'rev-parse',qualified+':'+scope),'tool_qualified_component_mismatch')
    names={n:('scripts/' if n=='production_manifest' else 'scripts/infra/')+n+'.py' for n in m.MODULES}
    dependencies=['build_connect_role_bot_update','build_feed_media_update','build_perf_query_update','build_studio_viewport_update',
        'build_studio_colors_update','build_studio_materials_update','build_course_studio_update','build_connect_telegram_update','build_connect_con02_update']
    names.update({n:'scripts/infra/'+n+'.py' for n in dependencies})
    for name,path in names.items():
        raw=git(repo,'show',tool_commit+':'+path)
        c.require((repo/path).read_bytes()==raw,'dirty_builder_dependency')
        if name not in ('connect_role_bot_update','build_connect_role_bot_update'):
            c.require(raw==git(repo,'show',m.PREDECESSOR_TOOL+':'+path),'frozen_predecessor_dependency_changed')
    for path in ('connect-worker/deploy/build_release.py','scripts/infra/workday_observer.py'):
        c.require((repo/path).read_bytes()==git(repo,'show',tool_commit+':'+path)
            ==git(repo,'show',m.APP_COMMIT+':'+path),'dirty_release_dependency')
    bundle=reference_from_git(repo,tool_commit)
    sources={n+'.py':git(repo,'show',tool_commit+':'+names[n]).decode() for n in m.MODULES}
    values=assets_for(bundle);assets={n:base64.b64encode(raw).decode() for n,raw in sorted(values.items())}
    manifest={'schema':1,'tool_commit':tool_commit,'app_commit':m.APP_COMMIT,'worker_commit':m.WORKER_COMMIT,
        'sources':{n:{'sha256':c.sha(raw.encode()),'bytes':len(raw.encode())} for n,raw in sorted(sources.items())},
        'assets':{n:{'sha256':c.sha(raw),'bytes':len(raw)} for n,raw in sorted(values.items())}}
    m.load_embedded(manifest,sources,assets)
    decoder=next(ast.get_source_segment(sources['connect_con02_contract.py'],n) for n in ast.parse(sources['connect_con02_contract.py']).body
        if isinstance(n,ast.FunctionDef) and n.name=='decode_envelope')
    header='''#!/usr/bin/env python3
"""Coordinated USTAR role bot application+worker update. Default offline PLAN_ONLY."""
import json,sys
import importlib.abc,importlib.util
from pathlib import Path
class Refusal(ValueError):pass
def require(value,reason):
    if not value:raise Refusal(reason)
def unique(pairs):
    result={}
    for key,value in pairs:
        require(key not in result,'duplicate_embedded_json_key');result[key]=value
    return result
'''
    constants=''.join(n+' = '+repr(json.dumps(v,sort_keys=True,ensure_ascii=True))+'\n'
        for n,v in [('MANIFEST_JSON',manifest),('SOURCES_JSON',sources),('ASSETS_JSON',assets)])
    bootstrap='''
try:
    manifest=json.loads(MANIFEST_JSON,object_pairs_hook=unique)
    sources=json.loads(SOURCES_JSON,object_pairs_hook=unique)
    assets=json.loads(ASSETS_JSON,object_pairs_hook=unique)
    decoded,_=decode_envelope(manifest,sources,assets,app_commit=APP_IDENTITY)
    require(set(decoded)==MODULE_INVENTORY,'embedded_module_inventory')
    parent=Path(__file__).absolute().parent
    sys.path[:]=[p for p in sys.path if p and Path(p).resolve() not in (parent,Path.cwd())]
    class Embedded(importlib.abc.MetaPathFinder,importlib.abc.Loader):
        def find_spec(self,name,path=None,target=None):
            return importlib.util.spec_from_loader(name,self) if name+'.py' in decoded else None
        def create_module(self,spec):return None
        def exec_module(self,module):
            module.__file__='<ustar-role-bot/'+module.__name__+'.py>'
            exec(compile(decoded[module.__name__+'.py'],module.__file__,'exec'),module.__dict__)
    sys.meta_path.insert(0,Embedded())
    for n in MODULE_INVENTORY:sys.modules.pop(n.removesuffix('.py'),None)
    from connect_role_bot_update import main,load_embedded
    bundle=load_embedded(manifest,sources,assets)
except BaseException:
    print('{"phase":"refused","reason":"embedded_bundle_invalid"}');sys.exit(2)
sys.exit(main(bundle=bundle))
'''
    raw=(header+decoder+'\n'+constants+'APP_IDENTITY = '+repr(m.APP_COMMIT)+'\nMODULE_INVENTORY = set('
        +repr(tuple(sorted(sources)))+')\n'+bootstrap).encode()
    c.require(len(raw)<=128*1024**2,'plain_artifact_size')
    output=Path(output);output.mkdir(parents=True,exist_ok=True)
    target=output/('ustar-connect-role-bot-update-'+tool_commit[:8]+'.py');target.write_bytes(raw);target.chmod(0o644)
    observer_parent=git(repo,'show',tool_commit+':scripts/infra/workday_observer.py')
    observer=derive_observer(observer_parent)
    metadata={'schema':1,'tool_commit':tool_commit,'app_commit':m.APP_COMMIT,'worker_commit':m.WORKER_COMMIT,
        'baseline_commit':m.BASELINE_COMMIT,'predecessor_tool':m.PREDECESSOR_TOOL,'worker_rollout':True,
        'payload_files':606,'tables':103,'versions':m.VERSIONS,'journal':str(m.BASE),'sha256':c.sha(raw),'bytes':len(raw),
        'default_host_access':False,'mini_app_enabled':False,'public_academy_HTTPS':False,'personal_opt_in_preserved':True,
        'sensitive_actions':'independent_native_Academy_POST_sesskey','observer_parent_sha256':c.sha(observer_parent),
        'observer_sha256':c.sha(observer),'observer_adaptation':'only_add_local2900_to_fixed_version_allowlist'}
    target.with_suffix('.py.sha256').write_text(c.sha(raw)+'  '+target.name+'\n')
    target.with_suffix('.py.metadata.json').write_text(json.dumps(metadata,sort_keys=True,indent=2)+'\n')
    readme=('USTAR Connect: приложение2803 ->2900 + согласованный worker\n'
        'App/worker: '+m.APP_COMMIT+'\nOperator: '+tool_commit+'\n\n'
        'Архив распаковать в новый root-owned0700 каталог; update.py root-owned0600.\n'
        'sha256sum --check --strict SHA256SUMS\npython3 -I -B update.py --check\n'
        'python3 -I -B -u update.py --upgrade --acknowledge-outage\npython3 -I -B update.py --status\n'
        'python3 -I -B update.py --check (role_bot_ready)\n\n'
        'Обновление включает бот после app_ready, с отдельным закрытым ключом. Привязки, ACK, курсор,\n'
        'личные настройки уведомлений и история сохраняются; получатели — связанные учётки с личным согласием,\n'
        'только новые события после включения. Mini App/внешний HTTPS не включаются.\n'
        'Для кадровых решений/оценок/переобучения/итога адаптации нужна защищённая native-сессия Академии\n'
        'из офисной сети. Бот не принимает пароли и не засчитывает просмотр чата за прохождение курса.\n\n'
        'Восстановление: --recover. При незавершённой конфигурации бота возвращает прежний worker/профиль,\n'
        'сохраняя новое приложение и записи. --enable-bot повторно включает ту же согласованную версию.\n'
        '--disable-bot отключает новый контур, сохраняя приложение2900 и прежние link/ACK.\n'
        'До публичного открытия recovery приложения возвращает парную2803 копию; после открытия завершает2900\n'
        'и сохраняет новые записи. Полный поздний --restore требует --acknowledge-outage и\n'
        '--discard-writes-since-snapshot: он удаляет ВСЕ записи после снимка. Не применять для обычного recovery.\n'
        'Пассивный сборщик observe.py по умолчанию PLAN_ONLY; явный --collect с новым output-dir.\n'
        'Журнал: '+str(m.BASE)+'. Не удалять lock и не править состояние.\n').encode()
    members={'update.py':raw,'observe.py':observer,'INSTALL.json':(json.dumps(metadata,sort_keys=True,indent=2)+'\n').encode(),'README.txt':readme}
    members['SHA256SUMS']=''.join(c.sha(v)+'  '+n+'\n' for n,v in sorted(members.items())).encode()
    archive=output/('ustar-connect-role-bot-install-'+tool_commit[:8]+'.tar.gz')
    with archive.open('wb') as stream,gzip.GzipFile(fileobj=stream,mode='wb',mtime=0,filename='') as gz,tarfile.open(fileobj=gz,mode='w') as tar:
        for name,value in sorted(members.items()):
            item=tarfile.TarInfo(name);item.size=len(value);item.mode=0o600;item.mtime=0;tar.addfile(item,io.BytesIO(value))
    archive.with_name(archive.name+'.sha256').write_text(c.sha(archive.read_bytes())+'  '+archive.name+'\n')
    print(json.dumps({'tool_commit':tool_commit,'app_commit':m.APP_COMMIT,'archive':str(archive),'bytes':archive.stat().st_size,
        'sha256':c.sha(archive.read_bytes()),'worker_rollout':True},sort_keys=True))
    return target


if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--commit',required=True);p.add_argument('--output-dir',required=True)
    args=p.parse_args();build(args.commit,args.output_dir)
